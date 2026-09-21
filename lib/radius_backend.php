<?php

include_once 'device_base.php';
include_once 'api_logger.php';
include_once 'pure_mysql.php';

class RadiusBackend extends Device_Base
{
    private ?PDO $pdo = null;
    private ?PureMysql $pureDb = null;
    private bool $usePure = false;
    private array $batch_failed = [];
    private array $batch_success = [];
    private ?object $batch_device = null;
    private array $conf_radius = [];
    private array $disconnects = [];
    private bool $transactionalSchemaChecked = false;

    public function __construct($data = null)
    {
        parent::__construct($data);
        $this->load_radius_config();
    }

    private function load_radius_config(): void
    {
        $conf = (array)$this->conf;
        $this->conf_radius = [
            'enabled' => (bool)($conf['radius_enabled'] ?? false),
            'host' => $conf['radius_host'] ?? '127.0.0.1',
            'port' => (int)($conf['radius_port'] ?? 3306),
            'db' => $conf['radius_db'] ?? 'radius',
            'user' => $conf['radius_user'] ?? 'radius',
            'pass' => $conf['radius_pass'] ?? '',
            'secret' => $conf['radius_secret'] ?? 'testing123',
            'coa_port' => (int)($conf['radius_coa_port'] ?? 3799),
            'timeout' => (int)($conf['radius_timeout'] ?? 5),
            'disabled_list' => $conf['disabled_list'] ?? 'disabled',
            'disabled_profile' => $conf['disabled_profile'] ?? 'disabled',
            'disabled_rate' => $conf['disabled_rate'] ?? 0,
        ];
    }

    private function connect(): bool
    {
        if ($this->pdo !== null || $this->pureDb !== null) {
            return true;
        }

        $cfg = $this->conf_radius;

        // 1. Try native PDO if pdo_mysql extension is available
        if (extension_loaded('pdo_mysql')) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'], $cfg['db']);
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => $cfg['timeout'],
                ];
                $this->pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], $options);
                $this->usePure = false;
                return true;
            } catch (Throwable $e) {
                MyLog()->Append('radius_pdo_connect_fail: ' . $e->getMessage());
            }
        }

        // 2. Fallback to Pure PHP socket client
        $pure = new PureMysql();
        if ($pure->connect($cfg['host'], $cfg['port'], $cfg['user'], $cfg['pass'], $cfg['db'], $cfg['timeout'])) {
            $this->pureDb = $pure;
            $this->usePure = true;
            return true;
        }

        MyLog()->Append('radius_connect_fail: ' . $pure->getLastError());
        return false;
    }

    private function db_execute(string $sql): bool
    {
        if ($this->pdo) {
            $this->pdo->exec($sql);
            return true;
        }
        if ($this->pureDb) {
            $res = $this->pureDb->query($sql);
            if ($res === false) {
                throw new RuntimeException($this->pureDb->getLastError());
            }
            return true;
        }
        return false;
    }

    private function db_select(string $sql): array
    {
        if ($this->pdo) {
            $stmt = $this->pdo->query($sql);
            return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        }
        if ($this->pureDb) {
            $res = $this->pureDb->query($sql);
            if ($res === false) {
                throw new RuntimeException($this->pureDb->getLastError());
            }
            return is_array($res) ? $res : [];
        }
        return [];
    }

    private function escape(string $val): string
    {
        if ($this->pdo) {
            $q = $this->pdo->quote($val);
            // strip outer quotes
            return substr($q, 1, -1);
        }
        if ($this->pureDb) {
            return $this->pureDb->escape($val);
        }
        return addslashes($val);
    }

    public function do_batch($device, $data): int
    {
        $this->batch_device = is_object($device) ? $device : (object)$device;
        $this->batch_success = [];
        $this->batch_failed = [];

        if (!$this->connect()) {
            $err = 'radius_db_unreachable';
            foreach ($data as $item) {
                $id = $item['batch'] ?? null;
                if ($id) $this->batch_failed[$id] = $err;
            }
            return 0;
        }

        // Register router in `nas` table if not already present
        if (!$this->transactionalSchemaChecked) {
            $rows = $this->db_select("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('radcheck','radreply','radusergroup','radgroupreply')");
            if (count($rows) !== 4) throw new RuntimeException('RADIUS schema is incomplete');
            foreach ($rows as $row) if (strtolower($row['ENGINE'] ?? '') !== 'innodb') throw new RuntimeException('RADIUS provisioning requires transactional InnoDB tables');
            $this->transactionalSchemaChecked = true;
        }
        $this->sync_nas_client($device);

        $writes = 0;
        foreach ($data as $item) {
            $id = $item['batch'] ?? null;
            $transaction = false;
            try {
                $this->disconnects = [];
                $this->db_execute('START TRANSACTION');
                $transaction = true;
                $written = $this->process_item($item);
                if (!$written) throw new RuntimeException('Invalid RADIUS operation');
                $this->db_execute('COMMIT');
                $transaction = false;
                foreach ($this->disconnects as [$username, $nas]) {
                    if (!$this->send_disconnect($username, $nas)) throw new RuntimeException('RADIUS disconnect not acknowledged');
                }
                if ($written) {
                    if ($id) $this->batch_success[$id] = $id;
                    $writes++;
                }
            } catch (Throwable $e) {
                if ($transaction) $this->db_execute('ROLLBACK');
                MyLog()->Append(['event' => 'radius_item_error', 'path' => $item['path'] ?? '', 'type' => get_class($e)]);
                if ($id) $this->batch_failed[$id] = 'RADIUS operation or disconnect failed';
            }
        }

        return $writes;
    }

    private function process_item(array $item): bool
    {
        $path = trim($item['path'] ?? '', '/');
        $action = $item['action'] ?? 'set';

        if (in_array($path, ['ppp/secret', 'ip/hotspot/user'], true)) {
            return $this->process_account($item, $action);
        }

        if (in_array($path, ['ppp/profile', 'ip/hotspot/user/profile'], true)) {
            return $this->process_profile($item, $action);
        }

        if (in_array($path, ['ppp/active', 'ip/hotspot/active'], true)) {
            $username = $item['name'] ?? null;
            if ($username && $this->batch_device) {
                $this->disconnects[] = [$username, $this->batch_device->ip];
            }
            return true;
        }

        if (in_array($path, ['queue/simple', 'ipv6/pool'], true)) return true; // Rates come from RADIUS profiles.
        throw new RuntimeException('Unsupported RADIUS provisioning path');
    }

    private function process_account(array $item, string $action): bool
    {
        $username = $item['name'] ?? null;
        if (!$username) return false;

        if ($action === 'remove') {
            $this->delete_user($username);
            if ($this->batch_device && !empty($this->batch_device->ip)) {
                $this->disconnects[] = [$username, $this->batch_device->ip];
            }
            return true;
        }

        $password = $item['password'] ?? '';
        $ip = $item['remote-address'] ?? $item['address'] ?? null;
        $profile = $item['profile'] ?? 'default';
        $prefix = $item['remote-ipv6-prefix'] ?? null;
        $rateLimit = $item['rate-limit'] ?? null;
        $addressList = $item['address-list'] ?? null;

        $isSuspended = ($profile === ($this->conf_radius['disabled_profile'] ?? 'disabled'))
            || ($addressList === ($this->conf_radius['disabled_list'] ?? 'disabled'));

        // 1. radcheck: credentials & auth
        $this->set_check_attribute($username, 'Cleartext-Password', ':=', $password);

        if ($isSuspended) {
            $dr = $this->conf_radius['disabled_rate'] ?? 0;
            if ($dr == 0) {
                $this->set_check_attribute($username, 'Auth-Type', ':=', 'Reject');
            } else {
                $this->delete_check_attribute($username, 'Auth-Type');
            }
        } else {
            $this->delete_check_attribute($username, 'Auth-Type');
        }

        // 2. radreply: Framed-IP-Address
        if ($ip) {
            $this->set_reply_attribute($username, 'Framed-IP-Address', ':=', $ip);
        } else {
            $this->delete_reply_attribute($username, 'Framed-IP-Address');
        }

        // 3. radreply: Framed-IPv6-Prefix
        if ($prefix) {
            $this->set_reply_attribute($username, 'Framed-IPv6-Prefix', ':=', $prefix);
        } else {
            $this->delete_reply_attribute($username, 'Framed-IPv6-Prefix');
        }

        // 4. radreply: Mikrotik-Group (Profile)
        $this->set_reply_attribute($username, 'Mikrotik-Group', ':=', $profile);

        // 5. radreply: Mikrotik-Rate-Limit
        if ($isSuspended) {
            $dr = $this->conf_radius['disabled_rate'] ?? 0;
            if ($dr > 0) {
                $rateStr = sprintf('%sk/%sk', $dr, $dr);
                $this->set_reply_attribute($username, 'Mikrotik-Rate-Limit', ':=', $rateStr);
            }
            if (!empty($this->conf_radius['disabled_list'])) {
                $this->set_reply_attribute($username, 'Mikrotik-Address-List', ':=', $this->conf_radius['disabled_list']);
            }
        } else {
            if ($rateLimit) {
                $this->set_reply_attribute($username, 'Mikrotik-Rate-Limit', ':=', $rateLimit);
            } else {
                $this->delete_reply_attribute($username, 'Mikrotik-Rate-Limit');
            }
            if ($addressList) {
                $this->set_reply_attribute($username, 'Mikrotik-Address-List', ':=', $addressList);
            } else {
                $this->delete_reply_attribute($username, 'Mikrotik-Address-List');
            }
        }

        // 6. radusergroup: map user to profile/plan group
        $this->set_user_group($username, $profile);

        // Send CoA / Disconnect if suspended or edited so router updates immediately
        if ($isSuspended && $this->batch_device && !empty($this->batch_device->ip)) {
            $this->disconnects[] = [$username, $this->batch_device->ip];
        }

        return true;
    }

    private function process_profile(array $item, string $action): bool
    {
        $groupname = $item['name'] ?? null;
        if (!$groupname) return false;

        $g = $this->escape($groupname);

        if ($action === 'remove') {
            $this->db_execute("DELETE FROM radgroupreply WHERE groupname = '$g'");
            return true;
        }

        $rateLimit = $item['rate-limit'] ?? null;
        $addressList = $item['address-list'] ?? null;

        if ($rateLimit) {
            $this->set_group_reply_attribute($groupname, 'Mikrotik-Rate-Limit', ':=', $rateLimit);
        } else {
            $this->db_execute("DELETE FROM radgroupreply WHERE groupname = '$g' AND attribute = 'Mikrotik-Rate-Limit'");
        }
        if ($addressList) {
            $this->set_group_reply_attribute($groupname, 'Mikrotik-Address-List', ':=', $addressList);
        } else {
            $this->db_execute("DELETE FROM radgroupreply WHERE groupname = '$g' AND attribute = 'Mikrotik-Address-List'");
        }

        return true;
    }

    private function sync_nas_client(object $device): void
    {
        $ip = $device->ip ?? null;
        if (!$ip) return;

        $secret = $this->conf_radius['secret'] ?? 'testing123';
        $shortname = $device->name ?? 'Router';

        $eIp = $this->escape($ip);
        $eShort = $this->escape($shortname);
        $eSec = $this->escape($secret);

        try {
            $rows = $this->db_select("SELECT id FROM nas WHERE nasname = '$eIp' LIMIT 1");
            if (!empty($rows)) {
                $this->db_execute("UPDATE nas SET shortname = '$eShort', secret = '$eSec' WHERE nasname = '$eIp'");
            } else {
                $this->db_execute("INSERT INTO nas (nasname, shortname, type, secret, description) VALUES ('$eIp', '$eShort', 'other', '$eSec', 'UISP Managed MikroTik')");
            }
        } catch (Throwable $e) {
            MyLog()->Append('radius_nas_sync_error: ' . $e->getMessage());
        }
    }

    private function set_check_attribute(string $user, string $attr, string $op, string $val): void
    {
        $u = $this->escape($user);
        $a = $this->escape($attr);
        $o = $this->escape($op);
        $v = $this->escape($val);

        $this->db_execute("DELETE FROM radcheck WHERE username = '$u' AND attribute = '$a'");
        $this->db_execute("INSERT INTO radcheck (username, attribute, op, value) VALUES ('$u', '$a', '$o', '$v')");
    }

    private function delete_check_attribute(string $user, string $attr): void
    {
        $u = $this->escape($user);
        $a = $this->escape($attr);
        $this->db_execute("DELETE FROM radcheck WHERE username = '$u' AND attribute = '$a'");
    }

    private function set_reply_attribute(string $user, string $attr, string $op, string $val): void
    {
        $u = $this->escape($user);
        $a = $this->escape($attr);
        $o = $this->escape($op);
        $v = $this->escape($val);

        $this->db_execute("DELETE FROM radreply WHERE username = '$u' AND attribute = '$a'");
        $this->db_execute("INSERT INTO radreply (username, attribute, op, value) VALUES ('$u', '$a', '$o', '$v')");
    }

    private function delete_reply_attribute(string $user, string $attr): void
    {
        $u = $this->escape($user);
        $a = $this->escape($attr);
        $this->db_execute("DELETE FROM radreply WHERE username = '$u' AND attribute = '$a'");
    }

    private function set_user_group(string $user, string $group): void
    {
        $u = $this->escape($user);
        $g = $this->escape($group);

        $this->db_execute("DELETE FROM radusergroup WHERE username = '$u'");
        $this->db_execute("INSERT INTO radusergroup (username, groupname, priority) VALUES ('$u', '$g', 1)");
    }

    private function set_group_reply_attribute(string $group, string $attr, string $op, string $val): void
    {
        $g = $this->escape($group);
        $a = $this->escape($attr);
        $o = $this->escape($op);
        $v = $this->escape($val);

        $this->db_execute("DELETE FROM radgroupreply WHERE groupname = '$g' AND attribute = '$a'");
        $this->db_execute("INSERT INTO radgroupreply (groupname, attribute, op, value) VALUES ('$g', '$a', '$o', '$v')");
    }

    private function delete_user(string $user): void
    {
        $u = $this->escape($user);
        $this->db_execute("DELETE FROM radcheck WHERE username = '$u'");
        $this->db_execute("DELETE FROM radreply WHERE username = '$u'");
        $this->db_execute("DELETE FROM radusergroup WHERE username = '$u'");
    }

    /**
     * Send RFC 3576 / RFC 5176 Disconnect-Request (PoD) UDP packet to MikroTik router port 3799
     */
    public function send_disconnect(string $username, string $nasIp): bool
    {
        $secret = $this->conf_radius['secret'] ?? 'testing123';
        $port = $this->conf_radius['coa_port'] ?? 3799;

        $code = 40; // Disconnect-Request
        $identifier = mt_rand(1, 255);
        if (strlen($username) > 253 || $username === '' || strlen($secret) < 16 || $secret === 'testing123') return false;

        $userAttr = pack('CC', 1, strlen($username) + 2) . $username;
        $nasAttr = '';
        if (filter_var($nasIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $nasAttr = pack('CC', 4, 6) . inet_pton($nasIp);
        }

        $attributes = $userAttr . $nasAttr;
        $length = 20 + strlen($attributes);
        $reqAuth = str_repeat("\x00", 16);

        $hash = md5(chr($code) . chr($identifier) . pack('n', $length) . $reqAuth . $attributes . $secret, true);
        $packet = chr($code) . chr($identifier) . pack('n', $length) . $hash . $attributes;

        try {
            $sock = @fsockopen('udp://' . $nasIp, $port, $errno, $errstr, 2);
            if ($sock) {
                try {
                    stream_set_timeout($sock, max(1, min(30, (int)($this->conf_radius['timeout'] ?? 5))));
                    if (fwrite($sock, $packet) !== strlen($packet)) return false;
                    $reply = fread($sock, 4096);
                    return is_string($reply) && self::validDisconnectReply($reply, $identifier, $hash, $secret);
                } finally { fclose($sock); }
            }
        } catch (Throwable $e) {
            MyLog()->Append('radius_coa_failed: ' . $e->getMessage());
        }

        return false;
    }

    public static function validDisconnectReply(string $reply, int $identifier, string $requestAuth, string $secret): bool
    {
        if (strlen($reply) < 20 || ord($reply[1]) !== $identifier) return false;
        $length = unpack('n', substr($reply, 2, 2))[1];
        if ($length !== strlen($reply) || !in_array(ord($reply[0]), [41, 42], true)) return false;
        $expected = md5(substr($reply, 0, 4) . $requestAuth . substr($reply, 20) . $secret, true);
        if (!hash_equals($expected, substr($reply, 4, 16))) return false;
        $absent = false;
        for ($offset = 20; $offset < $length;) {
            if ($offset + 2 > $length) return false;
            $size = ord($reply[$offset + 1]);
            if ($size < 2 || $offset + $size > $length) return false;
            if (ord($reply[$offset]) === 101 && $size === 6) $absent = unpack('N', substr($reply, $offset + 2, 4))[1] === 503;
            $offset += $size;
        }
        // An authenticated NAK reporting no session is an idempotent success.
        return ord($reply[0]) === 41 || $absent;
    }

    /**
     * Test connection to FreeRADIUS MySQL database directly
     * Supports both native PDO and Pure PHP socket client
     */
    public static function test_connection(array $config): array
    {
        $host = $config['radius_host'] ?? '127.0.0.1';
        $port = (int)($config['radius_port'] ?? 3306);
        $db = $config['radius_db'] ?? 'radius';
        $user = $config['radius_user'] ?? 'radius';
        $pass = $config['radius_pass'] ?? '';
        $timeout = (int)($config['radius_timeout'] ?? 5);

        // 1. Try PDO if pdo_mysql extension is available
        if (extension_loaded('pdo_mysql')) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $db);
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => $timeout,
                ]);

                $stmt = $pdo->query("SHOW TABLES LIKE 'radcheck'");
                $hasRadcheck = (bool)$stmt->fetch();

                if ($hasRadcheck) {
                    return [
                        'success' => true,
                        'message' => 'Successfully connected to FreeRADIUS MySQL database (native PDO driver)! Standard schema verified.',
                    ];
                } else {
                    return [
                        'success' => true,
                        'message' => 'Connected to MySQL database, but radcheck table was not found. Please run radius_schema.sql on your database.',
                    ];
                }
            } catch (Throwable $e) {
                return [
                    'success' => false,
                    'message' => 'Connection failed (PDO): ' . $e->getMessage(),
                ];
            }
        }

        // 2. Pure PHP socket client fallback
        $pure = new PureMysql();
        if ($pure->connect($host, $port, $user, $pass, $db, $timeout)) {
            $rows = $pure->query("SHOW TABLES LIKE 'radcheck'");
            $hasRadcheck = !empty($rows);
            $pure->close();

            if ($hasRadcheck) {
                return [
                    'success' => true,
                    'message' => 'Successfully connected to FreeRADIUS MySQL database (pure-PHP driver)! Standard schema verified.',
                ];
            } else {
                return [
                    'success' => true,
                    'message' => 'Connected to MySQL database, but radcheck table was not found. Please run radius_schema.sql on your database.',
                ];
            }
        }

        return [
            'success' => false,
            'message' => 'Connection failed: ' . $pure->getLastError(),
        ];
    }

    public function failed(): ?array
    {
        return $this->batch_failed;
    }

    public function success(): ?array
    {
        return $this->batch_success;
    }
}

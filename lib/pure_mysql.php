<?php
/**
 * Pure PHP MySQL / MariaDB Client
 * Lightweight socket-based MySQL client requiring zero PHP C-extensions.
 * Supports mysql_native_password authentication and standard queries.
 */
class PureMysql
{
    private $socket = null;
    private int $seq = 0;
    private string $serverVersion = '';
    private string $lastError = '';
    private int $affectedRows = 0;
    private int $lastInsertId = 0;

    public function connect(string $host, int $port, string $user, string $pass, string $db, int $timeout = 5): bool
    {
        $this->lastError = '';
        $target = sprintf('tcp://%s:%d', $host, $port);
        $errno = 0;
        $errstr = '';

        $this->socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$this->socket) {
            $this->lastError = sprintf('Connection failed: %s (%d)', $errstr ?: 'Network unreachable', $errno);
            return false;
        }

        stream_set_timeout($this->socket, $timeout);

        // 1. Read Initial Handshake Packet
        $packet = $this->readPacket();
        if ($packet === false || strlen($packet) < 4) {
            $this->lastError = 'Empty or invalid handshake packet from MySQL server';
            $this->close();
            return false;
        }

        if (ord($packet[0]) === 0xFF) {
            // Error packet
            $this->lastError = $this->parseErrorPacket($packet);
            $this->close();
            return false;
        }

        // Protocol version (byte 0)
        $protoVersion = ord($packet[0]);
        $pos = 1;

        // Server version (null-terminated string)
        $nullPos = strpos($packet, "\x00", $pos);
        if ($nullPos === false) {
            $this->lastError = 'Corrupt handshake: server version missing';
            $this->close();
            return false;
        }
        $this->serverVersion = substr($packet, $pos, $nullPos - $pos);
        $pos = $nullPos + 1;

        // Connection ID (4 bytes)
        $pos += 4;

        // Auth plugin data part 1 (8 bytes)
        $scramble1 = substr($packet, $pos, 8);
        $pos += 8;

        // 1 byte 0x00 filler
        $pos += 1;

        // Capability flags lower 2 bytes
        $capLow = unpack('v', substr($packet, $pos, 2))[1] ?? 0;
        $pos += 2;

        // Charset (1 byte)
        $pos += 1;

        // Status flags (2 bytes)
        $pos += 2;

        // Capability flags upper 2 bytes
        $capHigh = unpack('v', substr($packet, $pos, 2))[1] ?? 0;
        $pos += 2;

        $capabilities = ($capHigh << 16) | $capLow;

        // Auth plugin data length (1 byte)
        $authDataLen = ord($packet[$pos]);
        $pos += 1;

        // Reserved 10 bytes
        $pos += 10;

        // Scramble part 2 (usually 12 bytes + 1 byte null)
        $scramble2Len = max(13, $authDataLen - 8);
        $scramble2Raw = substr($packet, $pos, $scramble2Len);
        $scramble2 = rtrim($scramble2Raw, "\x00");

        $salt = $scramble1 . $scramble2;

        // 2. Compute Auth Scramble (SHA1)
        $authResponse = '';
        if ($pass !== '') {
            $stage1 = sha1($pass, true);
            $stage2 = sha1($stage1, true);
            $stage3 = sha1($salt . $stage2, true);
            $authResponse = $stage1 ^ $stage3;
        }

        // 3. Build Handshake Response 41
        // CLIENT_LONG_PASSWORD (1) | CLIENT_FOUND_ROWS (2) | CLIENT_LONG_FLAG (4) | CLIENT_CONNECT_WITH_DB (8)
        // | CLIENT_PROTOCOL_41 (512) | CLIENT_SECURE_CONNECTION (32768) | CLIENT_PLUGIN_AUTH (524288)
        $clientFlags = 0x0008820f;
        $maxPacketSize = 0x01000000; // 16MB
        $charset = 45; // utf8mb4_general_ci

        $body = pack('V', $clientFlags);
        $body .= pack('V', $maxPacketSize);
        $body .= chr($charset);
        $body .= str_repeat("\x00", 23); // filler
        $body .= $user . "\x00";
        $body .= chr(strlen($authResponse)) . $authResponse;
        $body .= $db . "\x00";
        $body .= "mysql_native_password\x00";

        $this->writePacket($body);

        // 4. Read Auth Result Packet
        $authResult = $this->readPacket();
        if ($authResult === false) {
            $this->lastError = 'No response to authentication request';
            $this->close();
            return false;
        }

        $resultCode = ord($authResult[0]);
        if ($resultCode === 0xFF) {
            $this->lastError = $this->parseErrorPacket($authResult);
            $this->close();
            return false;
        }

        if ($resultCode === 0xFE) {
            // Auth switch request
            $this->lastError = 'Server requested unsupported authentication switch';
            $this->close();
            return false;
        }

        if ($resultCode === 0x00) {
            // OK packet!
            return true;
        }

        $this->lastError = 'Unexpected authentication response: ' . $resultCode;
        $this->close();
        return false;
    }

    public function query(string $sql)
    {
        $this->lastError = '';
        if (!$this->socket) {
            $this->lastError = 'Not connected to MySQL server';
            return false;
        }

        // Reset sequence for command packet
        $this->seq = 0;

        // COM_QUERY = 0x03
        $packet = chr(0x03) . $sql;
        $this->writePacket($packet);

        $res = $this->readPacket();
        if ($res === false) {
            $this->lastError = 'Failed reading query response';
            return false;
        }

        $firstByte = ord($res[0]);

        // Error packet (0xFF)
        if ($firstByte === 0xFF) {
            $this->lastError = $this->parseErrorPacket($res);
            return false;
        }

        // OK packet (0x00)
        if ($firstByte === 0x00) {
            $pos = 1;
            $this->affectedRows = $this->readLengthEncodedInt($res, $pos);
            $this->lastInsertId = $this->readLengthEncodedInt($res, $pos);
            return true;
        }

        // Result set packet
        $colPos = 0;
        $columnCount = $this->readLengthEncodedInt($res, $colPos);
        $columns = [];

        // Read column definitions
        for ($i = 0; $i < $columnCount; $i++) {
            $colPacket = $this->readPacket();
            if ($colPacket === false) break;
            $cPos = 0;
            $catalog = $this->readLengthEncodedString($colPacket, $cPos);
            $schema = $this->readLengthEncodedString($colPacket, $cPos);
            $table = $this->readLengthEncodedString($colPacket, $cPos);
            $orgTable = $this->readLengthEncodedString($colPacket, $cPos);
            $name = $this->readLengthEncodedString($colPacket, $cPos);
            $columns[] = $name;
        }

        // Read EOF packet after column definitions (if present)
        $eof = $this->readPacket();

        // Read data rows
        $rows = [];
        while (true) {
            $rowPacket = $this->readPacket();
            if ($rowPacket === false) break;
            if (ord($rowPacket[0]) === 0xFE && strlen($rowPacket) < 9) {
                // EOF packet
                break;
            }
            if (ord($rowPacket[0]) === 0xFF) {
                // Error during fetch
                $this->lastError = $this->parseErrorPacket($rowPacket);
                return false;
            }

            $row = [];
            $rPos = 0;
            for ($i = 0; $i < $columnCount; $i++) {
                $val = $this->readLengthEncodedString($rowPacket, $rPos);
                $colName = $columns[$i] ?? ('col_' . $i);
                $row[$colName] = $val;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    public function escape(string $value): string
    {
        return str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $value
        );
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getAffectedRows(): int
    {
        return $this->affectedRows;
    }

    public function getLastInsertId(): int
    {
        return $this->lastInsertId;
    }

    public function close(): void
    {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function readPacket(): string|false
    {
        if (!$this->socket || feof($this->socket)) return false;

        $header = '';
        while (strlen($header) < 4) {
            $chunk = @fread($this->socket, 4 - strlen($header));
            if ($chunk === false || $chunk === '') return false;
            $header .= $chunk;
        }

        $len = ord($header[0]) | (ord($header[1]) << 8) | (ord($header[2]) << 16);
        $this->seq = ord($header[3]) + 1;

        $body = '';
        while (strlen($body) < $len) {
            $chunk = @fread($this->socket, $len - strlen($body));
            if ($chunk === false || $chunk === '') return false;
            $body .= $chunk;
        }

        return $body;
    }

    private function writePacket(string $body): bool
    {
        if (!$this->socket) return false;

        $len = strlen($body);
        $header = pack('V', $len);
        $header = substr($header, 0, 3) . chr($this->seq++);

        $data = $header . $body;
        $total = strlen($data);
        $written = 0;

        while ($written < $total) {
            $bytes = @fwrite($this->socket, substr($data, $written));
            if ($bytes === false || $bytes === 0) return false;
            $written += $bytes;
        }

        return true;
    }

    private function parseErrorPacket(string $packet): string
    {
        $code = 0;
        if (strlen($packet) >= 3) {
            $code = ord($packet[1]) | (ord($packet[2]) << 8);
        }
        $msg = substr($packet, 3);
        if (isset($msg[0]) && $msg[0] === '#') {
            $msg = substr($msg, 6); // Skip SQLSTATE
        }
        return sprintf('Error %d: %s', $code, trim($msg));
    }

    private function readLengthEncodedInt(string $data, int &$pos): ?int
    {
        if (!isset($data[$pos])) return null;
        $first = ord($data[$pos++]);
        if ($first < 0xFB) return $first;
        if ($first === 0xFB) return null; // NULL
        if ($first === 0xFC) {
            $val = ord($data[$pos]) | (ord($data[$pos + 1]) << 8);
            $pos += 2;
            return $val;
        }
        if ($first === 0xFD) {
            $val = ord($data[$pos]) | (ord($data[$pos + 1]) << 8) | (ord($data[$pos + 2]) << 16);
            $pos += 3;
            return $val;
        }
        if ($first === 0xFE) {
            $val = unpack('P', substr($data, $pos, 8))[1] ?? 0;
            $pos += 8;
            return $val;
        }
        return null;
    }

    private function readLengthEncodedString(string $data, int &$pos): ?string
    {
        $len = $this->readLengthEncodedInt($data, $pos);
        if ($len === null) return null;
        $str = substr($data, $pos, $len);
        $pos += $len;
        return $str;
    }

    public function __destruct()
    {
        $this->close();
    }
}

<?php
/*****************************
 *
 * RouterOS PHP API class v1.6
 * Author: Denis Basta
 * Contributors:
 *    Nick Barnes
 *    Ben Menking (ben [at] infotechsc [dot] com)
 *    Jeremy Jefferson (http://jeremyj.com)
 *    Cristian Deluxe (djcristiandeluxe [at] gmail [dot] com)
 *    Mikhail Moskalev (mmv.rus [at] gmail [dot] com)
 *
 * http://www.mikrotik.com
 * http://wiki.mikrotik.com/wiki/API_PHP_class
 *
 ******************************/

class RouterosAPI
{
    public $debug = false;
    public $connected = false;
    public $port = 8728;
    public $ssl = false;
    public $caFile = null;
    public $peerName = null;
    public $timeout = 5;
    public $attempts = 1;
    public $delay = 1;
    public $socket;
    public $error_no;
    public $error_str;
    private float $deadline = 0;
    private const MAX_WORD = 4194304;
    private const MAX_REPLY = 16777216;

    public function connect($ip, $login, $password)
    {
        $this->disconnect();
        $host = trim($ip, '[]');
        if (strpos($host, '://') !== false || preg_match('/[\\s\\/]/', $host)) throw new InvalidArgumentException('Invalid RouterOS host');
        $endpoint = strpos($host, ':') !== false ? '[' . $host . ']' : $host;
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false,
            'peer_name' => $this->peerName ?: $host, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT];
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) $ssl['crypto_method'] |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        if ($this->caFile) $ssl['cafile'] = $this->caFile;
        for ($attempt = 0; $attempt < $this->attempts; $attempt++) {
            try {
                $this->socket = @stream_socket_client(($this->ssl ? 'tls://' : 'tcp://') . $endpoint . ':' . (int)$this->port,
                    $this->error_no, $this->error_str, $this->timeout, STREAM_CLIENT_CONNECT,
                    stream_context_create(['ssl' => $ssl]));
                if ($this->socket) {
                    stream_set_timeout($this->socket, max(1, (int)$this->timeout));
                    $this->write('/login', false);
                    $this->write('=name=' . $login, false);
                    $this->write('=password=' . $password);
                    $reply = $this->read(false);
                    if (($reply[0] ?? '') === '!done') {
                        $challenge = null;
                        foreach ($reply as $word) if (strpos($word, '=ret=') === 0) $challenge = substr($word, 5);
                        if ($challenge !== null) {
                            if (!preg_match('/^[a-f0-9]{32}$/i', $challenge)) throw new RuntimeException('Invalid login challenge');
                            $this->write('/login', false);
                            $this->write('=name=' . $login, false);
                            $this->write('=response=00' . md5(chr(0) . $password . hex2bin($challenge)));
                            $reply = $this->read(false);
                        }
                        if (($reply[0] ?? '') === '!done') {
                            $this->connected = true;
                            return true;
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->error_str = 'RouterOS login or transport failed';
            }
            $this->disconnect();
            if ($attempt + 1 < $this->attempts) sleep($this->delay);
        }
        return false;
    }

    public function write($command, $param2 = true)
    {
        if (!is_resource($this->socket)) throw new RuntimeException('RouterOS socket is not connected');
        // A command is exactly one word; never trim passwords or split embedded newlines.
        $command = (string)$command;
        $this->deadline = microtime(true) + max(1, $this->timeout);
        $this->writeExact($this->encodeLength(strlen($command)) . $command);
        if (is_int($param2)) {
            $tag = '.tag=' . $param2;
            $this->writeExact($this->encodeLength(strlen($tag)) . $tag . chr(0));
        } elseif ($param2 === true && $command !== '') {
            $this->writeExact(chr(0));
        }
        return true;
    }

    private function writeExact(string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $n = fwrite($this->socket, substr($data, $offset));
            if ($n === false || $n === 0 || microtime(true) > $this->deadline) {
                $this->disconnect();
                throw new RuntimeException('RouterOS write interrupted or timed out');
            }
            $offset += $n;
        }
    }

    public function encodeLength($length)
    {
        if ($length < 0x80) return chr($length);
        if ($length < 0x4000) return pack('n', $length | 0x8000);
        if ($length < 0x200000) return substr(pack('N', $length | 0xC00000), 1);
        if ($length < 0x10000000) return pack('N', $length | 0xE0000000);
        return chr(0xF0) . pack('N', $length);
    }

    private function readExact(int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            if (!is_resource($this->socket)) throw new RuntimeException('RouterOS socket closed');
            $chunk = fread($this->socket, min(65536, $length - strlen($data)));
            $status = stream_get_meta_data($this->socket);
            if ($chunk === false || $chunk === '' || ($status['timed_out'] ?? false) || microtime(true) > $this->deadline) {
                $this->disconnect();
                throw new RuntimeException('RouterOS reply interrupted or timed out');
            }
            $data .= $chunk;
        }
        return $data;
    }

    private function readLength(): int
    {
        $byte = ord($this->readExact(1));
        if ($byte < 0x80) return $byte;
        if ($byte < 0xC0) { $length = $byte & 0x3F; $extra = 1; }
        elseif ($byte < 0xE0) { $length = $byte & 0x1F; $extra = 2; }
        elseif ($byte < 0xF0) { $length = $byte & 0x0F; $extra = 3; }
        elseif ($byte === 0xF0) { $length = 0; $extra = 4; }
        else throw new RuntimeException('Invalid RouterOS length prefix');
        for ($i = 0; $i < $extra; $i++) $length = ($length << 8) | ord($this->readExact(1));
        if ($length > self::MAX_WORD) throw new RuntimeException('RouterOS word exceeds safety limit');
        return $length;
    }

    public function read($parse = true)
    {
        $this->deadline = microtime(true) + max(1, $this->timeout);
        $reply = []; $sentence = []; $bytes = 0;
        try {
            while (true) {
                $length = $this->readLength();
                $bytes += $length + 1;
                if ($bytes > self::MAX_REPLY) throw new RuntimeException('RouterOS response exceeds safety limit');
                if ($length) { $sentence[] = $this->readExact($length); continue; }
                if (!$sentence) continue;
                $type = $sentence[0];
                $reply = array_merge($reply, $sentence);
                $sentence = [];
                if ($type === '!fatal') throw new RuntimeException('RouterOS terminated the API connection');
                if ($type === '!done') return $parse ? $this->parseResponse($reply) : $reply;
            }
        } catch (Throwable $e) { $this->disconnect(); throw $e; }
    }

    public function parseResponse($response)
    {
        $parsed = []; $current = null; $single = null;
        foreach ((array)$response as $word) {
            if (in_array($word, ['!re', '!trap', '!fatal'], true)) {
                if ($word === '!re') $current =& $parsed[];
                else $current =& $parsed[$word][];
            } elseif ($word === '!done' || $word === '!empty') {
                unset($current); $current = null;
            } elseif (isset($word[0]) && $word[0] === '=') {
                [$key, $value] = array_pad(explode('=', substr($word, 1), 2), 2, '');
                if ($key === 'ret') $single = $value;
                $current[$key] = $value;
            }
        }
        return !$parsed && $single !== null ? $single : $parsed;
    }

    public function comm($command, $arguments = [])
    {
        $this->write($command, !$arguments);
        $remaining = count($arguments);
        foreach ($arguments as $key => $value) {
            $word = isset($key[0]) && $key[0] === '?' ? "$key=$value" : "=$key=$value";
            $this->write($word, --$remaining === 0);
        }
        return $this->read();
    }

    public function isIterable($value) { return is_array($value) || $value instanceof Traversable; }
    public function debug($text) { /* Wire logging intentionally disabled: words contain credentials. */ }
    public function arrayChangeKeyName(&$array)
    {
        $result = [];
        foreach ($array as $key => $value) $result[str_replace(['-', '/'], '_', $key)] = $value;
        return $result;
    }
    public function parseResponse4Smarty($response)
    {
        $parsed = $this->parseResponse($response);
        if (!is_array($parsed)) return $parsed;
        foreach ($parsed as &$row) if (is_array($row)) $row = $this->arrayChangeKeyName($row);
        return $parsed;
    }
    public function disconnect()
    {
        if (is_resource($this->socket)) fclose($this->socket);
        $this->socket = null;
        $this->connected = false;
    }
    public function __destruct() { $this->disconnect(); }
}

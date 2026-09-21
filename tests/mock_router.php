<?php
// Local test server only; listen exclusively on the loopback interface.
$mode = $argv[1];
$context = stream_context_create(['ssl' => ['local_cert' => $argv[2] ?? '', 'allow_self_signed' => true, 'verify_peer' => false]]);
$server = stream_socket_server(($mode === 'tls' ? 'tls' : 'tcp') . '://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if (!$server) exit(1);
echo stream_socket_get_name($server, false), "\n"; flush();
$client = @stream_socket_accept($server, 5);
if (!$client) { fclose($server); exit(0); }
stream_set_timeout($client, 5);
function sentence($client): array {
    $words = [];
    while (true) {
        $byte = fread($client, 1);
        if ($byte === '') throw new RuntimeException('Client closed');
        $length = ord($byte);
        if ($length === 0) return $words;
        if ($length >= 128) throw new RuntimeException('Oversized test word');
        $word = '';
        while (strlen($word) < $length) {
            $chunk = fread($client, $length - strlen($word));
            if ($chunk === '') throw new RuntimeException('Truncated test word');
            $word .= $chunk;
        }
        $words[] = $word;
    }
}
function reply($client, array $words): void {
    $bytes = '';
    foreach ($words as $word) $bytes .= chr(strlen($word)) . $word;
    $bytes .= "\0";
    for ($i=0; $i<strlen($bytes); $i++) { fwrite($client,$bytes[$i]); usleep(1000); }
}
try {
    $login = sentence($client);
    if ($login !== ['/login','=name=lab','=password= a=b ']) exit(2);
    if ($mode === 'legacy') {
        $challenge = str_repeat('ab',16);
        reply($client, ['!done','=ret=' . $challenge]);
        $response = sentence($client);
        if ($response !== ['/login','=name=lab','=response=00' . md5("\0 a=b " . hex2bin($challenge))]) exit(3);
    }
    reply($client, ['!done']);
} finally { fclose($client); fclose($server); }

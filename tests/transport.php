<?php
require_once __DIR__ . '/../lib/routeros_api.class.php';
$temp = sys_get_temp_dir() . '/ros-tls-' . bin2hex(random_bytes(8));
mkdir($temp);
$config = $temp . '/openssl.cnf';
file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=extensions\n[dn]\nCN=localhost\n[extensions]\nsubjectAltName=DNS:localhost\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
$options = ['config'=>$config,'private_key_bits'=>2048,'digest_alg'=>'sha256'];
$key = openssl_pkey_new($options);
$csr = openssl_csr_new(['commonName'=>'localhost'], $key, $options);
$cert = openssl_csr_sign($csr, null, $key, 1, $options);
if (!$key || !$cert) throw new RuntimeException('Cannot generate local TLS fixture');
openssl_x509_export($cert, $certPem); openssl_pkey_export($key, $keyPem, null, $options);
file_put_contents($temp . '/ca.pem', $certPem);
file_put_contents($temp . '/server.pem', $certPem . $keyPem);
foreach ([['plain',null,true],['legacy',null,true],['tls','localhost',true],['tls','wrong-host.example',false],['tls',null,false]] as [$mode,$peer,$expected]) {
    $process = proc_open([PHP_BINARY,__DIR__ . '/mock_router.php',$mode,$temp . '/server.pem'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot start local test server');
    $actual = false;
    try {
        $address = trim(fgets($pipes[1]));
        if (!preg_match('/^127\.0\.0\.1:(\d+)$/', $address,$match)) throw new RuntimeException('Invalid mock address');
        $api = new RouterosAPI(); $api->port = (int)$match[1]; $api->timeout = 2;
        $api->ssl = $mode === 'tls'; $api->peerName = $peer;
        if ($peer !== null) $api->caFile = $temp . '/ca.pem';
        $actual = $api->connect('127.0.0.1','lab',' a=b ');
        $api->disconnect();
        if ($actual !== $expected) throw new RuntimeException('Unexpected ' . $mode . ' connection result');
        echo 'PASS: ' . $mode . ' / ' . ($peer ?? 'system trust') . ' => ' . ($actual ? 'authenticated' : 'rejected') . "\n";
    } finally {
        foreach ($pipes as $pipe) fclose($pipe);
        $code = proc_close($process);
        // A rejected TLS handshake means the mock never receives a login sentence.
        if ($code !== 0 && $actual !== false) throw new RuntimeException('Mock router failed: ' . $code);
    }
}
echo "Only loopback sockets used; no real router contacted.\n";

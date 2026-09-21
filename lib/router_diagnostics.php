<?php
require_once __DIR__ . '/routeros_api.class.php';

class RouterDiagnostics
{
    public static function probe(object $device): array
    {
        $api = new RouterosAPI();
        $api->port = (int)($device->port ?? 8728);
        $api->timeout = max(1, min(60, (int)($device->api_timeout ?? 5)));
        $api->ssl = ($device->api_transport ?? 'plain') === 'tls';
        $api->caFile = ($device->tls_ca_file ?? '') ?: null;
        $api->peerName = ($device->tls_peer_name ?? '') ?: null;
        if (!$api->connect($device->ip, $device->user, $device->password ?? '')) throw new RuntimeException('RouterOS API login failed');
        try {
            $resource = $api->comm('/system/resource/print', ['.proplist' => 'version,architecture-name,board-name']);
            if (isset($resource['!trap'])) throw new RuntimeException('Cannot read RouterOS system resources');
            $report = ['resource' => $resource[0] ?? [], 'capabilities' => [], 'warnings' => []];
            foreach (['ppp' => '/ppp/profile', 'hotspot' => '/ip/hotspot/user/profile', 'dhcp' => '/ip/dhcp-server', 'ipv6' => '/ipv6/pool', 'queues' => '/queue/simple'] as $key => $path) {
                $reply = $api->comm($path . '/print', ['.proplist' => '.id', '?name' => '__ros_capability_probe__']);
                $report['capabilities'][$key] = isset($reply['!trap']) ? 'unavailable or permission denied' : 'menu accessible';
            }
            $fasttrack = $api->comm('/ip/firewall/filter/print', ['.proplist' => '.id', '?action' => 'fasttrack-connection', '?disabled' => 'false']);
            if (isset($fasttrack['!trap'])) $report['warnings'][] = 'FastTrack could not be checked with this account.';
            elseif ($fasttrack) $report['warnings'][] = 'Enabled IPv4 FastTrack rules found. Verify subscriber traffic is excluded from FastTrack or simple queues may be bypassed.';
            if (!$api->ssl) $report['warnings'][] = 'Plain API transport: use a trusted management VPN or configure verified TLS.';
            $version = $report['resource']['version'] ?? '';
            if (!preg_match('/^(6\.49\.|7\.)/', $version)) $report['warnings'][] = 'Outside the targeted v6.49.x/v7 compatibility matrix.';
            $report['warnings'][] = 'Menu access is not a provisioning certification; validate packages, device-mode, permissions and shaping in a lab.';
            return $report;
        } finally { $api->disconnect(); }
    }
}

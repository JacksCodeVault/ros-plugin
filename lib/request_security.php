<?php

use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;
use Ubnt\UcrmPluginSdk\Security\PermissionNames;

class RequestSecurity
{
    public static function isWebhook($data): bool
    {
        return is_object($data) && in_array($data->entity ?? null, ['service', 'client'], true)
            && in_array($data->changeType ?? null, ['insert', 'edit', 'delete'], true)
            && !isset($data->target);
    }

    public static function validWebhookToken(string $expected, string $provided): bool
    {
        return strlen($expected) >= 32 && hash_equals($expected, $provided);
    }

    public static function authorize($data, bool $mutation)
    {
        if (self::isWebhook($data)) {
            $config = is_file('data/config.json') ? json_decode(file_get_contents('data/config.json'), true) : [];
            $token = $_SERVER['HTTP_X_ROS_WEBHOOK_TOKEN'] ?? $_GET['webhook_token'] ?? '';
            if (!is_string($token) || !self::validWebhookToken((string)($config['webhookToken'] ?? ''), $token)) {
                throw new RuntimeException('Webhook authentication required', 403);
            }
            return null;
        }
        $user = UcrmSecurity::create()->getUser();
        // All screens can expose sensitive operational data; require plugin edit rights.
        if (!$user || $user->isClient || !$user->hasEditPermission(PermissionNames::SYSTEM_PLUGINS)) {
            throw new RuntimeException('UISP plugin administrator permission required', 403);
        }
        if ($mutation && !self::validCsrf((string)($_SERVER['HTTP_X_ROS_CSRF'] ?? ''), (string)$user->userId)) {
            throw new RuntimeException('Session token expired; reload the panel', 403);
        }
        return $user;
    }

    private static function signature(string $expires, string $user): string
    {
        $options = json_decode(file_get_contents('ucrm.json'), true, 512, JSON_THROW_ON_ERROR);
        $key = $options['pluginAppKey'] ?? '';
        if (!$key) throw new RuntimeException('UISP plugin key unavailable');
        $cookies = [];
        foreach (['PHPSESSID', 'nms-crm-php-session-id', 'nms-session'] as $name) $cookies[$name] = $_COOKIE[$name] ?? '';
        return hash_hmac('sha256', $expires . ':' . $user . ':' . json_encode($cookies), $key);
    }
    public static function csrf(string $user): string
    {
        $expires = (string)(time() + 7200);
        return $expires . '.' . self::signature($expires, $user);
    }
    private static function validCsrf(string $token, string $user): bool
    {
        $parts = explode('.', $token, 2);
        return count($parts) === 2 && ctype_digit($parts[0]) && (int)$parts[0] >= time()
            && (int)$parts[0] <= time() + 7200 && hash_equals(self::signature($parts[0], $user), $parts[1]);
    }
}

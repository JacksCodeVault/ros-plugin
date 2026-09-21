<?php
/**
 * ROS Plugin - Installation Hook
 *
 * Executed once when the plugin is uploaded and enabled in UISP.
 * Validates system requirements, creates the data directory,
 * initializes databases, and writes a setup guide to the plugin log.
 */

chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';

use Ubnt\UcrmPluginSdk\Service\PluginLogManager;

$log = PluginLogManager::create();

// 1. Ensure the data directory exists
if (!is_dir('data')) {
    mkdir('data', 0755, true);
    $log->appendLog('[INSTALL] Created data/ directory.');
}

// 2. Validate required PHP extensions
$required_extensions = ['gmp', 'sqlite3', 'curl', 'json', 'openssl'];
$missing = [];
foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}

if (!empty($missing)) {
    $log->appendLog(sprintf(
        '[INSTALL] WARNING: Missing PHP extensions: %s. The plugin may not function correctly.',
        implode(', ', $missing)
    ));
} else {
    $log->appendLog('[INSTALL] All required PHP extensions verified (gmp, sqlite3, curl, json, openssl).');
}

// Optional extensions check
if (extension_loaded('pdo_mysql')) {
    $log->appendLog('[INSTALL] Optional extension pdo_mysql verified (available for FreeRADIUS mode).');
} else {
    $log->appendLog('[INSTALL] Optional extension pdo_mysql not detected (Direct API mode works normally; install pdo_mysql if using FreeRADIUS mode).');
}

// 3. Initialize databases if they don't exist
try {
    include_once 'lib/api_logger.php';
    include_once 'lib/api_common.php';
    include_once 'lib/api_cache.php';
    include_once 'lib/api_setup.php';
    include_once 'lib/job_store.php';
    $operationLock = new OperationLock();

    set_error_handler('myErrorHandler');
    run_setup();
    cache_setup();

    $log->appendLog('[INSTALL] Database initialized successfully.');
} catch (\Exception | Error $e) {
    $log->appendLog(sprintf('[INSTALL] Database init deferred: %s', $e->getMessage()));
}

// 4. Write setup guide to the plugin log
$log->appendLog('');
$log->appendLog('=== ROS Plugin v2.4.0 - Installation Complete ===');
$log->appendLog('Read docs/UPGRADING-2.4.md before enabling webhooks. A webhook authentication token is now required.');
$log->appendLog('');
$log->appendLog('Next steps:');
$log->appendLog('  1. Go to System > Plugins > Ros Plugin > Settings');
$log->appendLog('     - Add your UNMS API token (optional, for network topology features)');
$log->appendLog('     - Configure a random webhook token of at least 32 characters');
$log->appendLog('     - Add the same token to the webhook URL or X-ROS-Webhook-Token header');
$log->appendLog('     - Enable debugging only while diagnosing an issue');
$log->appendLog('  2. Open the Plugin Panel (System > Plugins > Ros Plugin > Panel)');
$log->appendLog('  3. Add your MikroTik router(s):');
$log->appendLog('     - Prefer verified API-SSL (usually port 8729) or a private management VPN');
$log->appendLog('  4. Configure custom attributes (Panel > Attributes > Auto-create)');
$log->appendLog('  5. Set up your service plans with QoS parameters (Panel > Plans)');
$log->appendLog('  6. On your MikroTik, ensure the API service is enabled:');
$log->appendLog('     /ip service enable api-ssl');
$log->appendLog('     - Use a dedicated least-privilege RouterOS group; never expose API to 0.0.0.0/0');
$log->appendLog('');
$log->appendLog('Subscribers will be automatically provisioned when services are');
$log->appendLog('created, edited, suspended, or terminated in UISP CRM.');
$log->appendLog('================================================');

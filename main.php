<?php
/**
 * ROS Plugin - Background Cron Entry Point
 *
 * Executed periodically by UISP's internal scheduler (typically every minute).
 * Handles: database setup/migrations, cache synchronization, and retry of
 * failed MikroTik provisioning jobs.
 */

chdir(__DIR__);
require_once __DIR__ . '/vendor/autoload.php';
include_once 'lib/api_logger.php';
include_once 'lib/api_common.php';
include_once 'lib/api_cache.php';
include_once 'lib/api_setup.php';
include_once 'lib/api_jobs.php';

try {
    $operationLock = new OperationLock();
    set_error_handler('myErrorHandler');

    // Run database setup and migrations if needed
    run_setup();

    // Sync UISP entity cache (runs here instead of public.php to avoid blocking web requests)
    cache_setup();
    cache_sync();

    // Process queued/failed MikroTik provisioning jobs
    $jobs = new ApiJobs();
    $jobs->run();
} catch (\Exception | Error $error) {
    MyLog()->Append('Cron failed: ' . get_class($error));
}

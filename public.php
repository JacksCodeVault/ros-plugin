<?php


chdir(__DIR__);
//include_once 'includes/cors.php';
require_once 'vendor/autoload.php';
include_once 'lib/api_logger.php';
include_once 'lib/api_common.php';
include_once 'lib/request_security.php';
include_once 'lib/job_store.php';

if (isset($_SERVER['REQUEST_METHOD'])
    && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') { //skip redirect for options
    exit();
}

$json = file_get_contents('php://input') ?? null;

try
{
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    if (strlen($json ?: '') > 2097152) throw new RuntimeException('Request too large', 413);
    $data = $json ? json_decode($json, false, 512, JSON_THROW_ON_ERROR) : null;
    if (($json || !empty($_FILES)) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new RuntimeException('POST required', 405);
    $mutation = RequestSecurity::isWebhook($data)
        || !empty($_FILES)
        || (($data->changeType ?? 'list') !== 'list');
    $currentUser = RequestSecurity::authorize($data, $mutation);
    $csrfToken = $currentUser ? RequestSecurity::csrf((string)$currentUser->userId) : '';
    if ($mutation) $operationLock = new OperationLock();
    include_once 'lib/api_cache.php';
    include_once 'lib/api_setup.php';

    set_error_handler('myErrorHandler');
    run_setup();
    cache_setup();

    include_once 'lib/api_router.php';

    if(isset($_FILES) && !empty($_FILES))
    {//backup restore
        $bak = $_FILES['backup'] ?? null ;
        if($bak){ backup_restore(); }
        header('content-type: application/json');
        echo '{"status":"ok","error":false,"message":"ok","data":[]}';
        exit();
    }

    if(!$json)
    {
        if (isset($_GET['page']) && $_GET['page'] == 'panel')
        { //load panel
            include 'public/panel/index.php';
        }
        exit();
    }

    $api = new ApiRouter($data);
    $api->route();
    $api->http_response();
}
catch ( \Exception | Error $error)
{
    $code = in_array($error->getCode(), [403, 405, 413], true) ? $error->getCode() : 503;
    http_response_code($code);
    header('Content-Type: application/json');
    MyLog()->Append('Request failed: ' . get_class($error));
    echo json_encode(['status' => 'failed', 'error' => true, 'message' => $code === 503 ? 'Operation unavailable; check configuration or retry shortly.' : $error->getMessage(), 'data' => []]);
}




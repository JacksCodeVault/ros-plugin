<?php

use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;

include_once 'api_logger.php';

function myErrorHandler($errno, $errstr, $errfile, $errline){
    MyLog()->Append(sprintf('error no: %s error: %s file %s line: %s',
        $errno,$errstr,$errfile,$errline),7);
}

function dbUpdateHandler(){
    MyLog()->Append('Update chunk rejected - this is normal');
}

/**
 * @throws \GuzzleHttp\Exception\GuzzleException
 */
function backup_restore(){
    include_once 'database_backup.php';
    $upload = $_FILES['backup'] ?? [];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException('Invalid backup upload');
    DatabaseBackup::restore($upload['tmp_name']);
}

function fail($event, $data = [])
{
    MyLog()->Append(['event' => $event, 'data' => $data]);
    respond($event,true,[]);
    exit();
}

function bail($event, $data = [])
{
    MyLog()->Append(['event' => $event, 'data' => $data]);
    respond($event,true,[]);
    exit();
}

function respond($msg,$err = false,$data = [])
{
    $status = $err ? 'failed' : 'ok';
    $response = [
        'status' => $status,
        'error' => $err,
        'message' => $msg,
        'data' => $data,
    ];
    if(!headers_sent()) header('content-type: application/json');
    if ($err && !headers_sent()) { // failed header
        http_response_code(422);
    }
    echo json_encode($response,JSON_PRETTY_PRINT);
}

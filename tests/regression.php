<?php
// Isolated tests: no UISP installation, router, or persistent database needed.
require_once __DIR__ . '/../lib/routeros_api.class.php';
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS: $message\n";
}

$api = new RouterosAPI();
$rows = $api->parseResponse(['!re', '=name=customer=a=b', '=comment=', '!done']);
check($rows === [['name' => 'customer=a=b', 'comment' => '']], 'Preserve equals signs and empty values');
check($api->parseResponse(['!empty', '!done']) === [], 'RouterOS 7.18 empty response');
check(isset($api->parseResponse(['!trap', '=message=bad=value', '!done'])['!trap']), 'Retain trap responses');
foreach (["", "\x05ab", "\x06!fatal\x00", "\x80"] as $bytes) {
    $api = new RouterosAPI();
    $api->socket = fopen('php://temp', 'w+');
    fwrite($api->socket, $bytes);
    rewind($api->socket);
    $api->connected = true;
    $failed = false;
    try { $api->read(); } catch (RuntimeException $e) { $failed = true; }
    check($failed && !$api->connected, 'Truncated reply or fatal EOF fails and closes transport');
}

// Load production classes without constructing UISP-backed services.
require_once __DIR__ . '/../lib/radius_backend.php';
class RecordingPDO extends PDO {
    public array $queries = [];
    public function __construct() {}
    public function exec(string $statement): int|false { $this->queries[] = $statement; return 1; }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return "'" . str_replace("'", "''", $string) . "'"; }
}
$reflection = new ReflectionClass(RadiusBackend::class);
$backend = $reflection->newInstanceWithoutConstructor();
$pdo = new RecordingPDO();
$property = $reflection->getProperty('pdo');
$property->setAccessible(true);
$property->setValue($backend, $pdo);
$method = $reflection->getMethod('process_item');
$method->setAccessible(true);
$method->invoke($backend, ['path' => '/ip/hotspot/user/profile/', 'name' => 'Gold', 'rate-limit' => '20M/20M']);
$sql = implode("\n", $pdo->queries);
check(strpos($sql, 'radgroupreply') !== false && strpos($sql, 'radcheck') === false, 'Hotspot profile updates group, not account');
$pdo->queries = [];
$method->invoke($backend, ['path' => '/ppp/secret', 'name' => 'subscriber', 'profile' => 'Gold']);
check(in_array("DELETE FROM radreply WHERE username = 'subscriber' AND attribute = 'Mikrotik-Rate-Limit'", $pdo->queries, true), 'Reactivation removes stale per-user rate override');

require_once __DIR__ . '/../lib/job_store.php';
require_once __DIR__ . '/../lib/request_security.php';
require_once __DIR__ . '/../lib/database_backup.php';
require_once __DIR__ . '/../lib/api_sqlite.php';
require_once __DIR__ . '/../lib/data.php';
require_once __DIR__ . '/../lib/mt.php';
require_once __DIR__ . '/../lib/api_timer.php';
require_once __DIR__ . '/../lib/batch.php';

$store = new JobStore(':memory:', null);
$old = ['service' => ['id' => 12], 'device' => ['ip' => 'old-router'], 'commands' => [['name' => 'old-user']], 'delete' => true];
$store->enqueue($old);
$store->enqueue(['service' => ['id' => 12], 'delete' => false]);
$job = $store->claim();
check($job['payload'] === $old, 'Retry retains immutable old-device and account snapshot');
$store->finish($job['job_id'], false, 1, 'offline');
check($store->claim() === null, 'Replacement waits for pending delete; backoff prevents immediate retry');
$store->finish($job['job_id'], true, 1);
$next = $store->claim();
check($next && !$next['payload']['delete'], 'Replacement becomes eligible after old delete acknowledgement');
$recovered = $store->claim();
check($recovered['job_id'] === $next['job_id'] && $recovered['attempts'] === 2, 'Interrupted worker recovers durable running job under exclusive lock');
$store->finish($next['job_id'], false, 8, 'retry limit');
check($store->listing()[0]['state'] === 'held', 'Retry exhaustion preserves job for review');
$store->supersede(12);
check($store->listing() === [], 'New desired state supersedes stale sets');

$api = new RouterosAPI();
$api->socket = fopen('php://temp', 'w+');
$word = '=password=  a=b' . "\n" . 'c  ';
$api->write($word);
rewind($api->socket);
check(stream_get_contents($api->socket) === $api->encodeLength(strlen($word)) . $word . "\0", 'Writer preserves password whitespace, equals and newlines');
$api->disconnect();
foreach ([0, 127, 128, 16383, 16384, 2097151, 2097152, 4194304] as $length) {
    $api->socket = fopen('php://temp', 'w+');
    fwrite($api->socket, $api->encodeLength($length)); rewind($api->socket);
    $ref = new ReflectionClass($api);
    $deadline = $ref->getProperty('deadline'); $deadline->setAccessible(true); $deadline->setValue($api, microtime(true) + 5);
    $decode = $ref->getMethod('readLength'); $decode->setAccessible(true);
    check($decode->invoke($api) === $length, 'API word length round trip: ' . $length);
    $api->disconnect();
}
$api->socket = fopen('php://temp', 'w+');
fwrite($api->socket, "\x05!done\0\x03!re\x09=name=two\0\x05!done\0"); rewind($api->socket);
check($api->read() === [], 'Reader consumes exactly one reply sentence sequence');
check($api->read() === [['name' => 'two']], 'Following buffered response remains intact');
$api->disconnect();

$secret = str_repeat('s', 32); $auth = str_repeat('a', 16); $identifier = 9;
$header = pack('CCn', 41, $identifier, 20);
$reply = $header . md5($header . $auth . $secret, true);
check(RadiusBackend::validDisconnectReply($reply, $identifier, $auth, $secret), 'Accept authenticated Disconnect ACK');
check(!RadiusBackend::validDisconnectReply($reply, 10, $auth, $secret), 'Reject mismatched Disconnect identifier');
check(!RadiusBackend::validDisconnectReply($reply, $identifier, $auth, 'wrong'), 'Reject forged Disconnect authenticator');
$attribute = pack('CCN', 101, 6, 503);
$header = pack('CCn', 42, $identifier, 26);
$reply = $header . md5($header . $auth . $attribute . $secret, true) . $attribute;
check(RadiusBackend::validDisconnectReply($reply, $identifier, $auth, $secret), 'No-session authenticated NAK is idempotent success');

check(RequestSecurity::validWebhookToken($secret, $secret), 'Accept configured webhook token');
check(!RequestSecurity::validWebhookToken('', '') && !RequestSecurity::validWebhookToken($secret, 'wrong'), 'Reject absent or invalid webhook token');
check(!RequestSecurity::isWebhook((object)['entity'=>'service','changeType'=>'update','target'=>'config']), 'Webhook shape cannot bypass administrative route authorization');
check(ApiLogger::redact(['password'=>'private', 'nested'=>['radius_pass'=>'private']]) === ['password'=>'[REDACTED]', 'nested'=>['radius_pass'=>'[REDACTED]']], 'Redact structured nested credentials');
check(strpos(ApiLogger::redact('failure {"password":"private"}'), 'private') === false, 'Redact embedded JSON credentials');
$rejected = false;
try { DatabaseBackup::name('../ucrm.json'); } catch (InvalidArgumentException $e) { $rejected = true; }
check($rejected, 'Reject backup path traversal');

class CommandData extends Data {
    protected function account(): ?array { return ['path'=>'/ppp/secret']; }
    protected function profile(): ?array { return ['path'=>'/ppp/profile']; }
    protected function queue(): ?array { return ['path'=>'/queue/simple']; }
}
$commands = (new CommandData())->get_data(['batch'=>'test-service'], []);
check(count(array_filter($commands, function ($command) { return $command['batch'] === 'test-service'; })) === 3, 'All generated dependencies carry service ownership');

class FakeRouter extends RouterosAPI {
    public array $commands = [];
    public function comm($command, $arguments = []) { $this->commands[] = [$command,$arguments]; return []; }
}
class TestMT extends MT {
    public array $filter = [];
    public array $sessions = [['.id'=>'*1'],['.id'=>'*2']];
    public FakeRouter $fake;
    public function __construct() { $this->fake = new FakeRouter(); $this->api = $this->fake; }
    protected function read($path, $filter = null) { $this->filter = $filter; return $this->sessions; }
    public function execute(array $command) { return $this->write($command); }
}
$mt = new TestMT();
$mt->execute(['action'=>'remove','path'=>'/ip/hotspot/active','name'=>'subscriber']);
check($mt->filter === ['user'=>'subscriber'] && count($mt->fake->commands) === 2, 'Hotspot removal finds user and disconnects every active session');
$mt->sessions = [];
check($mt->execute(['action'=>'remove','path'=>'/ip/hotspot/active','name'=>'subscriber']) === [], 'Already absent active session is successful');

class FailingBackend {
    public array $calls = [];
    public function do_batch($device, $commands) { $this->calls[] = $commands; return 0; }
    public function failed() { return ['service-1'=>'queue failed']; }
    public function success() { return []; }
}
$batch = new Batch(); $ref = new ReflectionClass($batch); $backend = new FailingBackend();
foreach (['_devices'=>[1=>['id'=>1,'type'=>'mikrotik','mode'=>'api']], '_apis'=>['mikrotik'=>$backend]] as $key=>$value) {
    $prop = $ref->getProperty($key); $prop->setAccessible(true); $prop->setValue($batch,$value);
}
$run = $ref->getMethod('run_batch'); $run->setAccessible(true);
$run->invoke($batch, [1=>['queues'=>[['batch'=>'service-1']], 'accounts'=>[['batch'=>'service-1']]]]);
check(count($backend->calls) === 1, 'Dependency failure prevents downstream account provisioning');

$db = new ApiSqlite(':memory:');
$db->exec('CREATE TABLE devices(id INTEGER PRIMARY KEY, password TEXT, name TEXT)');
$db->insert(['id'=>1,'password'=>" x'\"= y ",'name'=>'001'], 'devices');
$saved = $db->selectDeviceById(1);
check($saved->password === " x'\"= y " && $saved->name === '001', 'SQLite preserves quoted passwords and numeric-looking names');

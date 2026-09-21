<?php
// Integration tests use a unique temporary workspace, never installation data.
$root = dirname(__DIR__);
$temp = sys_get_temp_dir() . '/ros-storage-' . bin2hex(random_bytes(8));
mkdir($temp); mkdir($temp . '/data'); mkdir($temp . '/includes'); mkdir($temp . '/public');
set_include_path($root . PATH_SEPARATOR . $root . '/lib' . PATH_SEPARATOR . get_include_path());
chdir($temp);
set_error_handler(function ($severity, $message, $file, $line) { throw new ErrorException($message, 0, $severity, $file, $line); });
function verify($condition, $name) { if (!$condition) throw new RuntimeException($name); echo "PASS: $name\n"; }
require_once $root . '/lib/api_setup.php';
require_once $root . '/lib/job_store.php';
require_once $root . '/lib/database_backup.php';

copy($root . '/includes/schema.sql', 'includes/schema.sql');
copy($root . '/includes/conf_default.json', 'includes/conf_default.json');
$schema = file_get_contents('includes/schema.sql');
$legacy = preg_replace('/^.*"(?:api_transport|api_timeout|tls_peer_name|tls_ca_file)".*\R/m', '', $schema);
$seed = new SQLite3('data/data.db'); $seed->enableExceptions(true); $seed->exec($legacy);
$seed->exec("INSERT INTO config(key,value) VALUES('version','2.3.5'),('radius_pass','000123')");
$seed->exec("INSERT INTO devices(id,name,ip,password) VALUES(1,'Lab','192.0.2.1','secret')");
$seed->exec("INSERT INTO services(id,device,status) VALUES(10,1,1)");
$seed->close();
file_put_contents('data/queue.json', json_encode(['Q10'=>['id'=>10,'username'=>'old','device'=>1,'action'=>'delete']]));
$before = file_get_contents('data/queue.json');
copy('data/data.db', 'public/backup-0');
(new ApiSetup())->run();
verify(!is_file('public/backup-0') && count(glob('data/quarantine/backup-0-*')) === 1, 'Previously published backup moved to recoverable private quarantine');
$db = new SQLite3('data/data.db');
verify($db->querySingle('SELECT api_transport FROM devices WHERE id=1') === 'plain', 'Migration preserves plaintext transport default');
verify($db->querySingle('SELECT password FROM devices WHERE id=1') === 'secret', 'Migration preserves router credentials');
verify($db->querySingle('SELECT COUNT(*) FROM services') === 1, 'Migration preserves subscriber mappings');
verify(mySqlite()->readConfig()->radius_pass === '000123', 'Configuration preserves numeric-looking secrets');
verify(file_get_contents('data/queue.json') === $before, 'Setup cleanup does not erase legacy retry queue');
(new ApiSetup())->run();
verify($db->querySingle('SELECT COUNT(*) FROM services') === 1, 'Repeated setup is idempotent');
$store = new JobStore();
verify(count($store->listing()) === 1 && $store->listing()[0]['state'] === 'held', 'Legacy deletion imported safely as held snapshot');
verify(count((new JobStore())->listing()) === 1, 'Legacy migration runs once');
DatabaseBackup::snapshot('data/data.db', 'data/backup-6');
DatabaseBackup::validate('data/backup-6');
$db->exec("UPDATE devices SET name='Changed'");
DatabaseBackup::restore('data/backup-6');
verify($db->querySingle('SELECT name FROM devices WHERE id=1') === 'Lab', 'Validated restore replaces data through SQLite backup API');
verify(is_file('data/pre-restore.db'), 'Restore retains a recoverable pre-restore snapshot');
$lock = new OperationLock(); $blocked = false;
try { $second = new OperationLock(); } catch (RuntimeException $e) { $blocked = true; }
verify($blocked, 'Concurrent provisioning lock is rejected');
unset($lock);
$lock = new OperationLock(); unset($lock);
verify(true, 'Provisioning lock released after request');
$db->exec('CREATE TRIGGER bad AFTER INSERT ON devices BEGIN DELETE FROM services; END');
$rejected = false;
try { DatabaseBackup::validate('data/data.db'); } catch (RuntimeException $e) { $rejected = true; }
verify($rejected, 'Restore rejects unexpected executable schema');
$db->close();
echo "Temporary test fixture retained at $temp\n";

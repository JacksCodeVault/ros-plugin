<?php

/** Durable outbox. Entry points serialize provisioning with OperationLock. */
class JobStore
{
    private SQLite3 $db;
    public function __construct(string $path = 'data/jobs.db', ?string $legacy = 'data/queue.json')
    {
        $this->db = new SQLite3($path);
        $this->db->enableExceptions(true);
        $this->db->busyTimeout(5000);
        $this->db->exec('PRAGMA synchronous=FULL');
        $this->db->exec('CREATE TABLE IF NOT EXISTS jobs (job_id INTEGER PRIMARY KEY AUTOINCREMENT, payload TEXT NOT NULL, state TEXT NOT NULL DEFAULT "pending", attempts INTEGER NOT NULL DEFAULT 0, available INTEGER NOT NULL DEFAULT 0, error TEXT NOT NULL DEFAULT "")');
        $this->db->exec('CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT)');
        if ($legacy !== null) $this->migrate($legacy);
    }
    private function execute(string $sql, array $values = []): SQLite3Result
    {
        $stmt = $this->db->prepare($sql);
        foreach ($values as $key => $value) $stmt->bindValue(':' . $key, $value, is_int($value) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        return $stmt->execute();
    }
    private function migrate(string $path): void
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            if (!$this->db->querySingle("SELECT value FROM metadata WHERE key='legacy_imported'")) {
                $raw = is_file($path) ? trim(file_get_contents($path)) : '';
                $items = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($items)) throw new RuntimeException('Invalid legacy retry queue');
                foreach ($items as $item) $this->enqueue(['legacy' => $item], 'held', 'Legacy snapshot retained: review and reprovision current service manually.');
                $this->db->exec("INSERT INTO metadata VALUES ('legacy_imported','1')");
            }
            $this->db->exec('COMMIT');
        } catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function enqueue(array $payload, string $state = 'pending', string $error = ''): int
    {
        $this->execute('INSERT INTO jobs(payload,state,error) VALUES(:payload,:state,:error)', ['payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'state' => $state, 'error' => $error]);
        return $this->db->lastInsertRowID();
    }
    public function enqueueLatest(array $payload): int
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $this->supersede((int)$payload['service']['id']);
            $id = $this->enqueue($payload);
            $this->db->exec('COMMIT');
            return $id;
        } catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function retry(int $id): void
    {
        $row = $this->execute('SELECT payload FROM jobs WHERE job_id=:id', ['id' => $id])->fetchArray(SQLITE3_ASSOC);
        if (!$row || isset(json_decode($row['payload'], true)['legacy'])) throw new RuntimeException('Legacy snapshots require manual reconciliation');
        $this->execute("UPDATE jobs SET state='pending',attempts=0,available=0,error='' WHERE job_id=:id", ['id' => $id]);
    }
    public function claim(): ?array
    {
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            // The entry-point lock guarantees no other live provisioning worker.
            $this->db->exec("UPDATE jobs SET state='pending' WHERE state='running' AND attempts < 8");
            $this->db->exec("UPDATE jobs SET state='held',error='Retry limit reached' WHERE state='running' AND attempts >= 8");
            $rows = $this->execute("SELECT * FROM jobs WHERE state='pending' AND available<=:now ORDER BY job_id", ['now' => time()]);
            $row = false;
            while ($candidate = $rows->fetchArray(SQLITE3_ASSOC)) {
                $payload = json_decode($candidate['payload'], true);
                if (empty($payload['delete']) && $this->hasDelete((int)($payload['service']['id'] ?? 0))) continue;
                $row = $candidate;
                break;
            }
            if ($row) $this->execute("UPDATE jobs SET state='running',attempts=attempts+1 WHERE job_id=:id", ['id' => (int)$row['job_id']]);
            $this->db->exec('COMMIT');
            if (!$row) return null;
            $row['payload'] = json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR);
            $row['attempts']++;
            return $row;
        } catch (Throwable $e) { $this->db->exec('ROLLBACK'); throw $e; }
    }
    public function finish(int $id, bool $success, int $attempts, string $error = ''): void
    {
        if ($success) { $this->execute('DELETE FROM jobs WHERE job_id=:id', ['id' => $id]); return; }
        $this->execute('UPDATE jobs SET state=:state,available=:next,error=:error WHERE job_id=:id', ['state' => $attempts >= 8 ? 'held' : 'pending', 'next' => time() + min(3600, 30 * (2 ** min($attempts, 7))), 'error' => $error, 'id' => $id]);
    }
    public function start(int $id): void { $this->execute("UPDATE jobs SET state='running',attempts=attempts+1 WHERE job_id=:id", ['id' => $id]); }
    public function hasDelete(int $service): bool
    {
        $rows = $this->db->query('SELECT payload FROM jobs');
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $p = json_decode($row['payload'], true);
            if (!empty($p['delete']) && (int)($p['service']['id'] ?? 0) === $service) return true;
        }
        return false;
    }
    public function supersede(int $service): void
    {
        $rows = $this->db->query("SELECT job_id,payload FROM jobs WHERE state IN ('pending','held')");
        $ids = [];
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $payload = json_decode($row['payload'], true);
            if (($payload['service']['id'] ?? null) == $service && empty($payload['delete'])) $ids[] = (int)$row['job_id'];
        }
        foreach ($ids as $id) $this->execute('DELETE FROM jobs WHERE job_id=:id', ['id' => $id]);
    }
    public function listing(): array
    {
        $rows = $this->db->query('SELECT * FROM jobs ORDER BY job_id');
        $result = [];
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
            $payload = json_decode($row['payload'], true);
            $result[] = ['job_id' => $row['job_id'], 'id' => $payload['service']['id'] ?? $payload['legacy']['id'] ?? null, 'action' => isset($payload['legacy']) ? 'legacy review' : (!empty($payload['delete']) ? 'delete' : 'set'), 'state' => $row['state'], 'attempts' => $row['attempts'], 'error' => $row['error'], 'time' => $row['available'] ? date('c', $row['available']) : 'Ready'];
        }
        return $result;
    }
    public function clear(): void { $this->db->exec('DELETE FROM jobs'); }
    public function holdAll(string $reason): void { $this->execute("UPDATE jobs SET state='held',error=:reason", ['reason' => $reason]); }
}

class OperationLock
{
    private $handle;
    public function __construct(string $path = 'data/operations.lock')
    {
        $this->handle = fopen($path, 'c');
        if (!$this->handle || !flock($this->handle, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another provisioning operation is running; retry shortly.');
    }
    public function __destruct()
    {
        if (is_resource($this->handle)) { flock($this->handle, LOCK_UN); fclose($this->handle); }
    }
}

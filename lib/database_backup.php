<?php

class DatabaseBackup
{
    public static function quarantinePublished(): void
    {
        foreach (glob('public/backup-*') ?: [] as $path) {
            if (!preg_match('/^backup-[0-6]$/D', basename($path)) || !is_file($path)) continue;
            if (!is_dir('data/quarantine') && !mkdir('data/quarantine', 0700)) throw new RuntimeException('Cannot protect published backup');
            $destination = 'data/quarantine/' . basename($path) . '-' . bin2hex(random_bytes(6));
            if (!rename($path, $destination)) throw new RuntimeException('Cannot quarantine published backup');
        }
    }
    public static function name(string $name): string
    {
        if (!preg_match('/^backup-[0-6]$/D', $name)) throw new InvalidArgumentException('Invalid backup name');
        return $name;
    }
    public static function snapshot(string $source, string $destination): void
    {
        $src = new SQLite3($source, SQLITE3_OPEN_READONLY);
        $dst = new SQLite3($destination);
        try {
            $src->enableExceptions(true); $dst->enableExceptions(true);
            $dst->busyTimeout(5000);
            if (!$src->backup($dst)) throw new RuntimeException('Database snapshot failed');
        } finally { $src->close(); $dst->close(); }
    }
    public static function validate(string $path): void
    {
        if (!is_file($path) || filesize($path) > 104857600) throw new RuntimeException('Invalid backup size');
        $db = new SQLite3($path, SQLITE3_OPEN_READONLY);
        try {
            $db->enableExceptions(true);
            $db->exec('PRAGMA trusted_schema=OFF');
            if ($db->querySingle('PRAGMA integrity_check') !== 'ok') throw new RuntimeException('Corrupt backup');
            if ($db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('trigger','view')")) throw new RuntimeException('Unsupported backup schema');
            foreach (['devices' => ['id','ip','password'], 'services' => ['id','device'], 'network' => ['id','address'], 'config' => ['key','value'], 'plans' => ['id','name']] as $table => $required) {
                $columns = [];
                $rows = $db->query("PRAGMA table_info('$table')");
                while ($row = $rows->fetchArray(SQLITE3_ASSOC)) $columns[] = $row['name'];
                if (array_diff($required, $columns)) throw new RuntimeException('Not a compatible ROS plugin backup');
            }
        } finally { $db->close(); }
    }
    public static function restore(string $path): void
    {
        self::validate($path);
        self::snapshot('data/data.db', 'data/pre-restore.db');
        self::snapshot($path, 'data/data.db');
        include_once 'job_store.php';
        (new JobStore())->holdAll('Database restored: reconcile jobs against restored state before reprovisioning.');
    }
}

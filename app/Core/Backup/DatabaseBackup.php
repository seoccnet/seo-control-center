<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Backup;

use PDO;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Runtime;

/**
 * Database backups in pure PHP (no mysqldump on shared hosting): one
 * gzip-compressed SQL file per backup in storage/backups, streamed table by
 * table with an unbuffered query so memory stays flat. The encryption key
 * ring is never part of a backup (SECURITY.md); encrypted values stay
 * encrypted and need the same key ring after a restore.
 */
final readonly class DatabaseBackup
{
    public const string FILE_PATTERN = '/^scc-backup-\d{8}-\d{6}\.sql\.gz$/D';
    /** Tables whose rows are short-lived: structure only. */
    private const array SKIP_DATA = ['sessions', 'rate_limit_buckets', 'locks'];
    private const int ROWS_PER_INSERT = 200;
    private const array BINARY_TYPES = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob'];

    public function __construct(
        private Connection $db,
        private Clock $clock,
        private string $directory,
        private string $appVersion,
        private int $keep,
    ) {}

    public function create(bool $protectForRecovery = false): BackupFile
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The backup directory cannot be created.');
        }
        $stamp = $this->clock->now();
        for ($attempt = 0; ; ++$attempt) {
            if ($attempt >= 100) {
                throw new \RuntimeException('Cannot reserve a unique database backup file.');
            }
            $name = 'scc-backup-' . $stamp->modify('+' . $attempt . ' seconds')->format('Ymd-His') . '.sql.gz';
            $path = $this->directory . '/' . $name;
            $partial = $path . '.part';
            if (file_exists($path)) {
                continue;
            }
            $reservation = @fopen($partial, 'x');
            if ($reservation !== false) {
                fclose($reservation);
                Runtime::chmod($partial, 0o600);
                break;
            }
            if (!file_exists($partial)) {
                throw new \RuntimeException('The backup file cannot be written.');
            }
        }
        $gz = gzopen($partial, 'wb6');
        if ($gz === false) {
            @unlink($partial);
            throw new \RuntimeException('The backup file cannot be written.');
        }

        $pdo = $this->db->pdo();
        $snapshot = false;
        try {
            if ($pdo->inTransaction()) {
                throw new \RuntimeException('A database backup cannot start inside another transaction.');
            }
            // Maintenance blocks new work; this snapshot also covers writes from
            // requests or jobs that were already running when maintenance began.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
            $snapshot = true;
            $write = static function (string $sql) use ($gz): void {
                if (gzwrite($gz, $sql) !== strlen($sql)) {
                    throw new \RuntimeException('Writing the backup failed (disk full?).');
                }
            };
            $write(sprintf("-- SEO Control Center database backup\n-- app %s, created %s UTC, server %s\n", $this->appVersion, $this->clock->now()->format('Y-m-d H:i:s'), $this->db->serverVersion()->raw));
            $write("SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET UNIQUE_CHECKS = 0;\n");

            foreach ($this->tables() as $table) {
                $create = $this->db->fetchOne(sprintf('SHOW CREATE TABLE `%s`', $table));
                $write(sprintf("\n-- Table %s\nDROP TABLE IF EXISTS `%s`;\n%s;\n", $table, $table, (string) ($create['Create Table'] ?? '')));
                if (in_array($table, self::SKIP_DATA, true)) {
                    continue;
                }
                $this->dumpRows($pdo, $table, $write);
            }
            $write("\nSET FOREIGN_KEY_CHECKS = 1;\nSET UNIQUE_CHECKS = 1;\n-- end of backup\n");
            $pdo->commit();
            $snapshot = false;
        } catch (\Throwable $e) {
            if ($snapshot && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            gzclose($gz);
            @unlink($partial);
            throw $e;
        }
        if (!gzclose($gz)) {
            @unlink($partial);
            throw new \RuntimeException('The backup file could not be flushed completely.');
        }
        if (!rename($partial, $path)) {
            @unlink($partial);
            throw new \RuntimeException('The backup file cannot be finished.');
        }
        Runtime::chmod($path, 0o600); // all customer data: owner only
        if ($protectForRecovery && @file_put_contents($path . '.keep', 'update recovery') === false) {
            throw new \RuntimeException('Cannot protect the update backup from retention cleanup.');
        }
        $this->prune();

        return BackupFile::fromPath($path);
    }

    public function releaseRecoveryBackup(string $name): void
    {
        if (preg_match(self::FILE_PATTERN, $name) === 1) {
            @unlink($this->directory . '/' . $name . '.keep');
        }
    }

    /**
     * @return list<BackupFile> newest first
     */
    public function list(): array
    {
        $files = [];
        foreach (glob($this->directory . '/scc-backup-*.sql.gz') ?: [] as $path) {
            if (preg_match(self::FILE_PATTERN, basename($path)) === 1) {
                $files[] = BackupFile::fromPath($path);
            }
        }
        usort($files, static fn(BackupFile $a, BackupFile $b): int => strcmp($b->name, $a->name));

        return $files;
    }

    /**
     * Resolves a backup by its file name; names never contain paths.
     */
    public function find(string $name): ?BackupFile
    {
        if (preg_match(self::FILE_PATTERN, $name) !== 1 || !is_file($this->directory . '/' . $name)) {
            return null;
        }

        return BackupFile::fromPath($this->directory . '/' . $name);
    }

    /**
     * Replaces the whole database with a backup. Destructive: console only.
     *
     * @return int statements executed
     */
    public function restore(BackupFile $backup): int
    {
        $this->validateRestoreFile($backup);
        $gz = gzopen($backup->path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('The backup file cannot be read.');
        }
        $count = 0;
        $statement = '';
        try {
            while (($line = gzgets($gz)) !== false) {
                if ($statement === '' && (str_starts_with($line, '-- ') || trim($line) === '')) {
                    continue;
                }
                $statement .= $line;
                // Statements end with ";" at the end of a line; values never contain raw newlines (they are escaped).
                if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                    $this->db->statement(rtrim($statement, "\r\n;"));
                    $statement = '';
                    ++$count;
                }
            }
        } finally {
            gzclose($gz);
            $this->db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
        if (trim($statement) !== '') {
            throw new \RuntimeException('The backup ends with an incomplete statement; it may be truncated.');
        }

        return $count;
    }

    /** Validate the whole gzip stream before executing any destructive SQL. */
    private function validateRestoreFile(BackupFile $backup): void
    {
        $file = @fopen($backup->path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('The backup file cannot be read.');
        }
        try {
            if (fread($file, 2) !== "\x1f\x8b" || fseek($file, -8, SEEK_END) !== 0) {
                throw new \RuntimeException('The backup is not a complete gzip file. No restore statements were executed.');
            }
            $trailer = fread($file, 8);
            if (!is_string($trailer) || strlen($trailer) !== 8) {
                throw new \RuntimeException('The backup gzip trailer is missing. No restore statements were executed.');
            }
        } finally {
            fclose($file);
        }
        $gz = @gzopen($backup->path, 'rb');
        if ($gz === false) {
            throw new \RuntimeException('The backup file cannot be read.');
        }
        $tail = '';
        $crc = hash_init('crc32b');
        $length = 0;
        try {
            while (true) {
                $chunk = @gzread($gz, 65536);
                if ($chunk === false) {
                    throw new \RuntimeException('The backup gzip stream is damaged. No restore statements were executed.');
                }
                if ($chunk === '') {
                    if (!gzeof($gz)) {
                        throw new \RuntimeException('The backup gzip stream is damaged. No restore statements were executed.');
                    }
                    break;
                }
                hash_update($crc, $chunk);
                $length = ($length + strlen($chunk)) & 0xffffffff;
                $tail = substr($tail . $chunk, -256);
            }
        } finally {
            gzclose($gz);
        }
        if (!hash_equals(bin2hex(strrev(substr($trailer, 0, 4))), hash_final($crc)) || substr($trailer, 4, 4) !== pack('V', $length)) {
            throw new \RuntimeException('The backup gzip checksum or length is invalid. No restore statements were executed.');
        }
        if (!str_ends_with(rtrim($tail), '-- end of backup')) {
            throw new \RuntimeException('The backup is incomplete. No restore statements were executed.');
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return array_map('strval', array_column($this->db->fetchAll(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
        ), 'name'));
    }

    /**
     * @param \Closure(string): void $write
     */
    private function dumpRows(PDO $pdo, string $table, \Closure $write): void
    {
        $binary = [];
        foreach ($this->db->fetchAll(
            'SELECT COLUMN_NAME AS name, DATA_TYPE AS type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$table],
        ) as $column) {
            $binary[(string) $column['name']] = in_array(strtolower((string) $column['type']), self::BINARY_TYPES, true);
        }

        $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, false);
        try {
            $result = $pdo->query(sprintf('SELECT * FROM `%s`', $table));
            if ($result === false) {
                throw new \RuntimeException('Reading ' . $table . ' failed.');
            }
            $columns = null;
            $rows = [];
            while (($row = $result->fetch(PDO::FETCH_ASSOC)) !== false) {
                /** @var array<string, mixed> $row */
                $columns ??= '(`' . implode('`, `', array_keys($row)) . '`)';
                $values = [];
                foreach ($row as $name => $value) {
                    $values[] = match (true) {
                        $value === null => 'NULL',
                        ($binary[$name] ?? false) => $value === '' ? "''" : '0x' . bin2hex((string) $value),
                        default => (string) $pdo->quote((string) $value),
                    };
                }
                $rows[] = '(' . implode(', ', $values) . ')';
                if (count($rows) >= self::ROWS_PER_INSERT) {
                    $write(sprintf("INSERT INTO `%s` %s VALUES %s;\n", $table, $columns, implode(', ', $rows)));
                    $rows = [];
                }
            }
            if ($rows !== [] && $columns !== null) {
                $write(sprintf("INSERT INTO `%s` %s VALUES %s;\n", $table, $columns, implode(', ', $rows)));
            }
            $result->closeCursor();
        } finally {
            $pdo->setAttribute(\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    private function prune(): void
    {
        foreach (array_slice($this->list(), max(1, $this->keep)) as $old) {
            if (!is_file($old->path . '.keep')) {
                @unlink($old->path);
            }
        }
    }
}

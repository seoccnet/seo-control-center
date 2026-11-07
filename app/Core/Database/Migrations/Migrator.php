<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database\Migrations;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use Throwable;

/**
 * Runs pending migrations in filename order, once, under a lease lock.
 *
 * Tracking table: `migrations`. The migrator also creates the `locks` table
 * because it needs a lock before the first migration can run.
 */
final class Migrator
{
    private const string NAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+$/D';

    /**
     * @param list<string> $paths migration directories
     */
    public function __construct(
        private readonly Connection $db,
        private readonly array $paths,
        private readonly LoggerInterface $logger,
        private readonly int $lockTtlSeconds = 600,
    ) {}

    /**
     * Discovers migration files across all paths, sorted by name.
     *
     * @return array<string, MigrationFile>
     */
    public function discover(): array
    {
        $files = [];
        foreach ($this->paths as $path) {
            foreach (glob(rtrim($path, '/\\') . '/*.php') ?: [] as $file) {
                $name = basename($file, '.php');
                if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                    throw new MigrationException(sprintf('Invalid migration file name "%s".', basename($file)));
                }
                if (isset($files[$name])) {
                    throw new MigrationException(sprintf('Duplicate migration name "%s".', $name));
                }
                $checksum = hash_file('sha256', $file);
                if ($checksum === false) {
                    throw new MigrationException(sprintf('Unable to read migration "%s".', $name));
                }
                $files[$name] = new MigrationFile($name, $file, $checksum);
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return list<MigrationFile>
     */
    public function pending(): array
    {
        $this->ensureInfrastructure();
        $applied = $this->appliedRows();

        return array_values(array_filter($this->discover(), static fn(MigrationFile $f): bool => !isset($applied[$f->name])));
    }

    /**
     * Applies all pending migrations. Returns the names applied.
     *
     * @param (callable(string): void)|null $onApplied progress callback
     * @return list<string>
     */
    public function migrate(?callable $onApplied = null): array
    {
        $this->ensureInfrastructure();

        // A session lock cannot expire while a long ALTER TABLE is running.
        // Keep the lease too, for interoperability with older application code.
        $sessionLock = 'scc.migrations.' . substr(hash('sha256', (string) $this->db->fetchValue('SELECT DATABASE()')), 0, 40);
        if ((int) $this->db->fetchValue('SELECT GET_LOCK(?, 0)', [$sessionLock]) !== 1) {
            throw new MigrationException('Another migration run is in progress. Try again shortly.');
        }

        $lock = new LeaseLock($this->db, 'migrations', $this->lockTtlSeconds);
        $applied = [];
        try {
            if (!$lock->acquire()) {
                throw new MigrationException('Another migration run is in progress. Try again shortly.');
            }
            foreach ($this->status() as $status) {
                if ($status->modifiedAfterApply || $status->missingFile) {
                    throw new MigrationException(sprintf('Applied migration %s was modified or removed. Restore its released file before migrating.', $status->name));
                }
            }
            $pending = $this->pending();
            if ($pending === []) {
                return [];
            }
            $batch = (int) $this->db->fetchValue('SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations');

            foreach ($pending as $file) {
                if (!$lock->renew()) {
                    throw new MigrationException('Lost the migration lock; stopping to stay safe.');
                }
                $this->logger->info('Applying migration {migration}', ['migration' => $file->name]);

                try {
                    $file->load()->up($this->db);
                } catch (Throwable $e) {
                    throw new MigrationException(sprintf('Migration %s failed: %s', $file->name, $e->getMessage()), 0, $e);
                }

                $this->db->execute(
                    'INSERT INTO migrations (migration, batch, checksum, applied_at) VALUES (:migration, :batch, :checksum, UTC_TIMESTAMP())',
                    ['migration' => $file->name, 'batch' => $batch, 'checksum' => $file->checksum],
                );
                $applied[] = $file->name;
                if ($onApplied !== null) {
                    $onApplied($file->name);
                }
            }
        } finally {
            try {
                $lock->release();
            } finally {
                $this->db->fetchValue('SELECT RELEASE_LOCK(?)', [$sessionLock]);
            }
        }

        return $applied;
    }

    /**
     * @return list<MigrationStatus>
     */
    public function status(): array
    {
        $this->ensureInfrastructure();
        $files = $this->discover();
        $applied = $this->appliedRows();
        $result = [];

        foreach ($files as $name => $file) {
            $row = $applied[$name] ?? null;
            $result[] = new MigrationStatus(
                $name,
                $row !== null,
                $row !== null ? (string) $row['applied_at'] : null,
                $row !== null ? (int) $row['batch'] : null,
                $row !== null && $row['checksum'] !== $file->checksum && !LegacyChecksums::accepts($name, (string) $row['checksum'], $file->checksum),
                false,
            );
        }
        foreach ($applied as $name => $row) {
            if (!isset($files[$name])) {
                $result[] = new MigrationStatus($name, true, (string) $row['applied_at'], (int) $row['batch'], false, true);
            }
        }
        usort($result, static fn(MigrationStatus $a, MigrationStatus $b): int => strcmp($a->name, $b->name));

        return $result;
    }

    private function ensureInfrastructure(): void
    {
        $this->db->statement(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                migration  VARCHAR(191) NOT NULL,
                batch      INT UNSIGNED NOT NULL,
                checksum   CHAR(64)     NOT NULL,
                applied_at DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY migrations_migration_unique (migration)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
        $this->db->statement(LeaseLock::createTableSql());
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function appliedRows(): array
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT migration, batch, checksum, applied_at FROM migrations ORDER BY migration') as $row) {
            $rows[(string) $row['migration']] = $row;
        }

        return $rows;
    }
}

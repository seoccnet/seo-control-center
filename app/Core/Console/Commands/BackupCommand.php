<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Backup\BackupFile;
use SeoControlCenter\Core\Backup\DatabaseBackup;
use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Support\Runtime;

/**
 * backup:create, backup:list and backup:restore <file> --force.
 */
final readonly class BackupCommand implements Command
{
    public function __construct(
        private DatabaseBackup $backups,
        private string $action,
    ) {}

    public function name(): string
    {
        return 'backup:' . $this->action;
    }

    public function description(): string
    {
        return match ($this->action) {
            'create' => 'Back up the database to storage/backups (gzip SQL; the key ring is not included)',
            'list' => 'List database backups',
            default => 'Replace the database with a backup: backup:restore <file> --force',
        };
    }

    public function run(array $arguments, Output $output): int
    {
        return match ($this->action) {
            'create' => $this->create($output),
            'list' => $this->list($output),
            default => $this->restore($arguments, $output),
        };
    }

    private function create(Output $output): int
    {
        Runtime::allowLongRun();
        $started = microtime(true);
        $backup = $this->backups->create();
        $output->line(sprintf('Created %s (%s) in %.1f s.', $backup->name, self::size($backup->size), microtime(true) - $started));
        $output->line('Keep a copy of storage/keys (or APP_KEYS) separately: encrypted settings cannot be read without it.');

        return 0;
    }

    private function list(Output $output): int
    {
        $backups = $this->backups->list();
        if ($backups === []) {
            $output->line('No backups yet. Create one with: php bin/console backup:create');

            return 0;
        }
        $output->table(['File', 'Size', 'Created (UTC)'], array_map(static fn(BackupFile $b): array => [$b->name, self::size($b->size), $b->createdAt], $backups));

        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function restore(array $arguments, Output $output): int
    {
        $name = $arguments[0] ?? '';
        $backup = $this->backups->find(basename($name));
        if ($backup === null) {
            $output->error('Backup not found. Use a file name from backup:list.');

            return 1;
        }
        if (!in_array('--force', $arguments, true)) {
            $output->error(sprintf('This replaces the ENTIRE database with %s (%s). Create a fresh backup first, then run again with --force.', $backup->name, $backup->createdAt));

            return 1;
        }
        Runtime::allowLongRun();
        $count = $this->backups->restore($backup);
        $output->line(sprintf('Restored %s (%d statements). Run "php bin/console migrate" if the backup is older than this version.', $backup->name, $count));

        return 0;
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%.0f KB', max(1, $bytes / 1024));
    }
}

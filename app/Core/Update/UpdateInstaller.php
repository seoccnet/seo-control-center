<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Update;

use Closure;
use SeoControlCenter\Core\Backup\DatabaseBackup;
use SeoControlCenter\Core\Support\Runtime;
use ZipArchive;

/**
 * Installs a verified release (UPDATES.md -> Update workflow): database
 * backup, maintenance mode, extract to staging, copy changed files
 * atomically, remove files the release no longer has, migrations, cache
 * reset, version check. Every step is written to storage/updates/state.json.
 *
 * If a step after copying fails, maintenance mode stays on and the same
 * package can simply be installed again (copying is idempotent), or the
 * backup restored.
 */
final readonly class UpdateInstaller
{
    /** Directories fully owned by releases: files not in the manifest are removed. */
    private const array MANAGED_DIRS = ['app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'vendor', 'bin'];

    /**
     * @param Closure(): list<string> $migrate runs pending migrations, returns their names
     */
    public function __construct(
        private string $appRoot,
        private ?DatabaseBackup $backups,
        private MaintenanceMode $maintenance,
        private Closure $migrate,
    ) {}

    /**
     * @return array{version: string, changed: int, removed: int, migrations: list<string>, backup: ?string}
     * @throws UpdateException
     */
    public function install(string $zipPath, ReleaseManifest $manifest): array
    {
        return UpdateLock::run($this->appRoot, fn(): array => $this->installLocked($zipPath, $manifest));
    }

    /**
     * @return array{version: string, changed: int, removed: int, migrations: list<string>, backup: ?string}
     */
    private function installLocked(string $zipPath, ReleaseManifest $manifest): array
    {
        Runtime::allowLongRun();
        $staging = $this->appRoot . '/storage/updates/staging-' . $manifest->version . '-' . bin2hex(random_bytes(6));
        $step = 'prepare';
        $applying = false;
        $backup = null;
        $wasMaintenance = $this->maintenance->isEnabled();
        $previous = $this->lastState();
        $identity = hash('sha256', $manifest->toJson());
        $unfinished = is_array($previous) && in_array($previous['status'] ?? null, ['running', 'failed'], true) && ($previous['applying'] ?? false) === true;
        if ($unfinished && ($previous['manifest_hash'] ?? null) !== $identity) {
            throw new UpdateException('An interrupted update must be recovered with the same signed package before installing another release.');
        }
        $installed = json_decode((string) @file_get_contents($this->appRoot . '/version.json'), true);
        $fromVersion = $unfinished ? ($previous['from_version'] ?? null) : ($installed['version'] ?? null);
        $recovery = ['manifest_hash' => $identity, 'from_version' => $fromVersion, 'backup' => $unfinished ? ($previous['backup'] ?? null) : null, 'applying' => $unfinished];

        try {
            foreach (self::MANAGED_DIRS as $directory) {
                $this->assertSafeTarget($directory . '/.scc-update-check');
            }
            foreach (array_keys($manifest->files) as $path) {
                $this->assertSafeTarget($path);
            }
            $this->state($manifest->version, 'running', $step = 'extract', recovery: $recovery);
            $this->extract($zipPath, $staging, $manifest);

            $this->state($manifest->version, 'running', $step = 'maintenance', recovery: $recovery);
            $this->maintenance->enable('Updating to ' . $manifest->version);

            $this->state($manifest->version, 'running', $step = 'backup', recovery: $recovery);
            if ($unfinished) {
                $backup = is_string($recovery['backup']) ? $recovery['backup'] : null;
                if ($this->backups !== null && ($backup === null || $this->backups->find($backup) === null)) {
                    throw new UpdateException('The original pre-update backup is missing. Recover that backup file before retrying this update.');
                }
            } else {
                $backup = $this->backups?->create(protectForRecovery: true)->name;
            }
            $recovery['backup'] = $backup;
            // Persist the recovery boundary before the first application file changes.
            $recovery['applying'] = true;
            $this->state($manifest->version, 'running', $step = 'copy', recovery: $recovery);
            $applying = true;
            $changed = $this->copy($staging, $manifest);

            $this->state($manifest->version, 'running', $step = 'cleanup', recovery: $recovery);
            $removed = $this->removeLeftovers($manifest);

            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }

            $this->state($manifest->version, 'running', $step = 'migrate', recovery: $recovery);
            $migrations = ($this->migrate)();

            $this->state($manifest->version, 'running', $step = 'verify', recovery: $recovery);
            $installed = json_decode((string) @file_get_contents($this->appRoot . '/version.json'), true);
            if (!is_array($installed) || ($installed['version'] ?? null) !== $manifest->version) {
                throw new UpdateException('After copying, version.json does not show the new version.');
            }

            $this->state($manifest->version, 'running', $step = 'finish', recovery: $recovery);
            $this->maintenance->disable();
            $this->state($manifest->version, 'completed', 'done', recovery: $recovery);
            if ($backup !== null) {
                $this->backups?->releaseRecoveryBackup($backup);
            }
            self::removeTree($staging);
        } catch (\Throwable $e) {
            $recovery['applying'] = $applying || $unfinished;
            // A retry failing during extraction must not reopen a partially upgraded site.
            if ($applying || $unfinished) {
                $this->maintenance->enable('Update recovery required for ' . $manifest->version);
            } elseif (!$wasMaintenance) {
                $this->maintenance->disable();
            }
            if (!$applying && !$unfinished && $backup !== null) {
                $this->backups?->releaseRecoveryBackup($backup);
            }
            $this->state($manifest->version, 'failed', $step, $e->getMessage(), $recovery);
            self::removeTree($staging);
            throw $e instanceof UpdateException ? $e : new UpdateException(sprintf('The update failed at step "%s": %s', $step, $e->getMessage()), 0, $e);
        }

        return ['version' => $manifest->version, 'changed' => $changed, 'removed' => $removed, 'migrations' => $migrations, 'backup' => $backup];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lastState(): ?array
    {
        $state = json_decode((string) @file_get_contents($this->stateFile()), true);

        return is_array($state) ? $state : null;
    }

    public function verifyPackage(string $path, string $publicKey, string $currentVersion): ReleaseManifest
    {
        $state = $this->lastState();
        $hash = is_array($state) && in_array($state['status'] ?? null, ['running', 'failed'], true) && ($state['applying'] ?? false) === true && is_string($state['manifest_hash'] ?? null)
            ? $state['manifest_hash'] : null;

        return ReleasePackage::verify($path, $publicKey, $currentVersion, recoveryManifestHash: $hash);
    }

    private function extract(string $zipPath, string $staging, ReleaseManifest $manifest): void
    {
        self::removeTree($staging);
        if (!mkdir($staging, 0o750, true) && !is_dir($staging)) {
            throw new UpdateException('The staging directory cannot be created.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new UpdateException('The package cannot be opened.');
        }
        try {
            foreach ($manifest->files as $path => $hash) {
                $content = $zip->getFromName($path);
                if (!is_string($content) || !hash_equals($hash, hash('sha256', $content))) {
                    throw new UpdateException(sprintf('The file %s changed after verification.', $path));
                }
                $target = $staging . '/' . $path;
                if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o755, true) && !is_dir(dirname($target))) {
                    throw new UpdateException('Cannot create ' . dirname($path));
                }
                if (file_put_contents($target, $content) === false) {
                    throw new UpdateException('Cannot write ' . $path . ' to the staging directory (disk full?).');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function copy(string $staging, ReleaseManifest $manifest): int
    {
        $changed = 0;
        foreach ($manifest->files as $path => $hash) {
            $this->assertSafeTarget($path);
            $target = $this->appRoot . '/' . $path;
            if (is_file($target) && hash_equals($hash, (string) hash_file('sha256', $target))) {
                continue;
            }
            $dir = dirname($target);
            if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
                throw new UpdateException('Cannot create ' . dirname($path));
            }
            $temporary = $target . '.scc-update';
            if (is_link($temporary)) {
                throw new UpdateException('An update temporary file is a symbolic link: ' . $path);
            }
            if (!copy($staging . '/' . $path, $temporary) || !rename($temporary, $target)) {
                @unlink($temporary);
                throw new UpdateException('Cannot replace ' . $path . ' (file permissions?).');
            }
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($target, true);
            }
            ++$changed;
        }

        return $changed;
    }

    private function assertSafeTarget(string $path): void
    {
        ReleaseManifest::assertAllowedPath($path);
        $target = $this->appRoot;
        foreach (explode('/', $path) as $segment) {
            $target .= '/' . $segment;
            if (is_link($target)) {
                throw new UpdateException('Cannot update through a symbolic link: ' . $path);
            }
        }
    }

    private function removeLeftovers(ReleaseManifest $manifest): int
    {
        $removed = 0;
        foreach (self::MANAGED_DIRS as $dir) {
            $root = $this->appRoot . '/' . $dir;
            if (!is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($this->appRoot) + 1));
                if (!isset($manifest->files[$relative])) {
                    if (!@unlink($file->getPathname())) {
                        throw new UpdateException('Cannot remove obsolete file ' . $relative . ' (file permissions?).');
                    }
                    ++$removed;
                }
            }
        }

        return $removed;
    }

    /** @param array<string, mixed> $recovery */
    private function state(string $version, string $status, string $step, ?string $error = null, array $recovery = []): void
    {
        $dir = dirname($this->stateFile());
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0o750, true) && !is_dir($dir)) {
                throw new UpdateException('Cannot create update recovery state.');
            }
        }
        $json = json_encode([
            'version' => $version,
            'status' => $status,
            'step' => $step,
            'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'at' => gmdate('Y-m-d H:i:s'),
        ] + $recovery, JSON_THROW_ON_ERROR);
        $temporary = tempnam($dir, 'state-');
        if ($temporary === false) {
            throw new UpdateException('Cannot write update recovery state.');
        }
        try {
            if (@file_put_contents($temporary, $json) !== strlen($json) || !@rename($temporary, $this->stateFile())) {
                throw new UpdateException('Cannot save update recovery state. No further update steps can run.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    private function stateFile(): string
    {
        return $this->appRoot . '/storage/updates/state.json';
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}

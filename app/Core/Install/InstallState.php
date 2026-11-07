<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Install;

use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\Support\Runtime;

/**
 * Whether the web installer is still available (DEPLOYMENT.md -> Installation wizard).
 *
 * Installed when storage/installed.lock exists. Installations that were set
 * up by hand (an .env file and no pending installer run) also count as
 * installed, so developer machines never see the installer.
 */
final readonly class InstallState
{
    public function __construct(private Paths $paths) {}

    public function isInstalled(): bool
    {
        if (is_file($this->lockFile())) {
            return true;
        }

        return is_file($this->envFile()) && !is_file($this->pendingFile());
    }

    public function lockFile(): string
    {
        return $this->paths->storage('installed.lock');
    }

    public function pendingFile(): string
    {
        return $this->paths->storage('install.pending');
    }

    public function envFile(): string
    {
        return $this->paths->base . '/.env';
    }

    public function setupKeyFile(): string
    {
        return $this->paths->storage('setup-key.txt');
    }

    /**
     * The setup key proves that the person running the installer controls the
     * server's files (they must read it via FTP / File Manager). Created on first use.
     */
    public function setupKey(): string
    {
        $file = $this->setupKeyFile();
        $existing = is_file($file) ? trim((string) file_get_contents($file)) : '';
        if (preg_match('/^[0-9a-f]{32}$/D', $existing) === 1) {
            return $existing;
        }
        $key = bin2hex(random_bytes(16));
        if (file_put_contents($file, $key . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write storage/setup-key.txt — make the storage directory writable.');
        }
        Runtime::chmod($file, 0o600);

        return $key;
    }

    public function markPending(): void
    {
        file_put_contents($this->pendingFile(), gmdate('c') . "\n");
    }

    public function markInstalled(): void
    {
        file_put_contents($this->lockFile(), 'Installed ' . gmdate('c') . ". Delete only to re-run the installer on an empty database.\n", LOCK_EX);
        @unlink($this->pendingFile());
        @unlink($this->setupKeyFile());
    }
}

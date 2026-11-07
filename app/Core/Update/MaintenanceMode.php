<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Update;

/**
 * Maintenance mode during updates: a flag file in storage. While it is set,
 * the site answers 503 except for sign-in and the updates page, and cron
 * does not run jobs.
 */
final readonly class MaintenanceMode
{
    private const string FILE = '/storage/maintenance.json';

    public function __construct(private string $appRoot) {}

    public function enable(string $reason): void
    {
        if (@file_put_contents($this->appRoot . self::FILE, json_encode(['since' => gmdate('Y-m-d H:i:s'), 'reason' => $reason], JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new UpdateException('Cannot enable maintenance mode. No update files were copied.');
        }
    }

    public function disable(): void
    {
        if (is_file($this->appRoot . self::FILE) && !@unlink($this->appRoot . self::FILE)) {
            throw new UpdateException('Cannot disable maintenance mode. Check storage permissions and retry.');
        }
    }

    public function isEnabled(): bool
    {
        return is_file($this->appRoot . self::FILE);
    }

    /**
     * Paths that stay reachable so an administrator can finish or repair an update.
     * public/index.php repeats this pattern because it runs before the autoloader.
     */
    public static function allows(string $path): bool
    {
        return preg_match('#^/(?:login|logout|verify|system/updates|system/health)(?:/|$)|^/assets/#', $path) === 1;
    }
}

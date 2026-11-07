<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System\Health;

use DateTimeImmutable;
use DateTimeZone;
use SeoControlCenter\Core\Backup\DatabaseBackup;
use SeoControlCenter\Core\Crypto\KeyRing;
use SeoControlCenter\Core\Crypto\KeyRingLoader;
use SeoControlCenter\Core\Crypto\SecretRotator;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\HostingCompatibility;
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\Support\SystemState;
use Throwable;

/**
 * Collects the System Health checks (DEPLOYMENT.md -> Diagnostics).
 * Every check is isolated: one failing check never hides the others.
 * Output never contains secrets.
 */
final readonly class SystemHealth
{
    private const array REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring', 'openssl', 'sodium', 'filter', 'zlib'];
    private const array RECOMMENDED_EXTENSIONS = ['intl', 'zip'];

    public function __construct(
        private Connection $db,
        private Paths $paths,
        private Queue $queue,
        private SystemState $state,
        private KeyRing $keys,
        private KeyRingLoader $keyLoader,
        private SecretRotator $rotator,
        private Clock $clock,
        private string $appVersion,
        private string $appUrl,
        private ?DatabaseBackup $backups = null,
        private int $cronIntervalMinutes = 1,
    ) {}

    /**
     * @return list<HealthCheck>
     */
    public function run(): array
    {
        $checks = [];
        foreach ([
            $this->application(...),
            $this->php(...),
            $this->hosting(...),
            $this->database(...),
            $this->storage(...),
            $this->encryption(...),
            $this->backupChecks(...),
            $this->cron(...),
            $this->queueChecks(...),
            $this->integrations(...),
        ] as $section) {
            try {
                array_push($checks, ...$section());
            } catch (Throwable $e) {
                $checks[] = new HealthCheck('System', 'Health check', HealthCheck::ERROR, 'A check failed to run: ' . $e::class);
            }
        }

        return $checks;
    }

    /**
     * @return list<HealthCheck>
     */
    private function application(): array
    {
        $https = str_starts_with(strtolower($this->appUrl), 'https://');

        return [
            new HealthCheck('Application', 'Version', HealthCheck::INFO, $this->appVersion),
            new HealthCheck('Application', 'HTTPS', $https ? HealthCheck::OK : HealthCheck::WARNING, $https ? 'APP_URL uses HTTPS' : 'APP_URL is not HTTPS (fine for local development only)'),
        ];
    }

    /**
     * @return list<HealthCheck>
     */
    private function php(): array
    {
        $checks = [new HealthCheck(
            'PHP',
            'Web PHP version',
            version_compare(PHP_VERSION, '8.5.0', '>=') ? HealthCheck::OK : HealthCheck::ERROR,
            PHP_VERSION . ' (minimum 8.5, ADR-012)',
        )];
        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, static fn(string $e): bool => !extension_loaded($e)));
        $checks[] = new HealthCheck('PHP', 'Required extensions', $missing === [] ? HealthCheck::OK : HealthCheck::ERROR, $missing === [] ? 'All present' : 'Missing: ' . implode(', ', $missing));
        $missingRec = array_values(array_filter(self::RECOMMENDED_EXTENSIONS, static fn(string $e): bool => !extension_loaded($e)));
        $checks[] = new HealthCheck('PHP', 'Recommended extensions', $missingRec === [] ? HealthCheck::OK : HealthCheck::WARNING, $missingRec === [] ? 'All present' : 'Missing: ' . implode(', ', $missingRec));
        $checks[] = new HealthCheck('PHP', 'Limits', HealthCheck::INFO, sprintf('memory_limit %s, max_execution_time %ss', ini_get('memory_limit'), ini_get('max_execution_time')));

        return $checks;
    }

    /**
     * @return list<HealthCheck>
     */
    private function hosting(): array
    {
        return array_map(
            static fn(array $c): HealthCheck => new HealthCheck('Hosting', $c['name'], $c['level'], $c['detail']),
            (new HostingCompatibility($this->paths))->check(),
        );
    }

    /**
     * @return list<HealthCheck>
     */
    private function database(): array
    {
        $version = $this->db->serverVersion();

        return [new HealthCheck(
            'Database',
            'Server',
            $version->isSupported() ? HealthCheck::OK : HealthCheck::ERROR,
            sprintf('%s (minimum %s %s)', $version->describe(), $version->product(), $version->minimum()),
        )];
    }

    /**
     * @return list<HealthCheck>
     */
    private function storage(): array
    {
        $checks = [];
        foreach (['cache', 'logs', 'sessions', 'tmp', 'updates'] as $dir) {
            $path = $this->paths->storage($dir);
            $ok = is_dir($path) && is_writable($path);
            $checks[] = new HealthCheck('Storage', 'storage/' . $dir, $ok ? HealthCheck::OK : HealthCheck::ERROR, $ok ? 'Writable' : 'Not writable');
        }
        $publicStorage = is_dir($this->paths->base . '/public/storage');
        if ($publicStorage) {
            $checks[] = new HealthCheck('Storage', 'Public exposure', HealthCheck::ERROR, 'A storage directory exists inside /public');
        }

        return $checks;
    }

    /**
     * @return list<HealthCheck>
     */
    private function encryption(): array
    {
        if (!$this->keys->isConfigured()) {
            return [new HealthCheck('Encryption', 'Key ring', HealthCheck::ERROR, 'Not configured. Run "php bin/console crypto:key:generate".')];
        }
        $checks = [new HealthCheck('Encryption', 'Key ring', HealthCheck::OK, sprintf('Active key #%d, %d key(s), source: %s', $this->keys->activeId(), count($this->keys->ids()), $this->keyLoader->usesEnvironment() ? 'environment' : 'file'))];

        if (!$this->keyLoader->usesEnvironment()) {
            $path = $this->keyLoader->path();
            $real = realpath($path) ?: $path;
            $publicDir = realpath($this->paths->base . '/public') ?: $this->paths->base . '/public';
            if (str_starts_with($real, $publicDir)) {
                $checks[] = new HealthCheck('Encryption', 'Key ring location', HealthCheck::ERROR, 'The key ring is inside the public web root.');
            } elseif (DIRECTORY_SEPARATOR === '/' && (fileperms($path) & 0o004) !== 0) {
                $checks[] = new HealthCheck('Encryption', 'Key ring permissions', HealthCheck::WARNING, 'The key ring file is world-readable; use chmod 600.');
            } else {
                $checks[] = new HealthCheck('Encryption', 'Key ring location', HealthCheck::OK, 'Outside the public web root. Keep an offline backup — it is excluded from backups.');
            }
        }

        $byKey = $this->rotator->countByKey();
        $old = array_sum(array_filter($byKey, fn(int $count, int $id): bool => $id !== $this->keys->activeId(), ARRAY_FILTER_USE_BOTH));
        $checks[] = new HealthCheck('Encryption', 'Secrets on retired keys', $old === 0 ? HealthCheck::OK : HealthCheck::WARNING, $old === 0 ? 'None' : sprintf('%d value(s); run "php bin/console crypto:rotate"', $old));

        return $checks;
    }

    /**
     * @return list<HealthCheck>
     */
    private function backupChecks(): array
    {
        if ($this->backups === null) {
            return [];
        }
        $latest = $this->backups->list()[0] ?? null;
        if ($latest === null) {
            return [new HealthCheck('Backups', 'Database backup', HealthCheck::WARNING, 'No backup yet. Create one under System → Backups (or php bin/console backup:create).')];
        }
        $age = $this->clock->now()->getTimestamp() - (int) strtotime($latest->createdAt . ' UTC');

        return [new HealthCheck(
            'Backups',
            'Database backup',
            $age > 7 * 86400 ? HealthCheck::WARNING : HealthCheck::OK,
            sprintf('Latest %s (%s ago); host backups and an offline copy of the key ring are still recommended', $latest->name, self::ago($age)),
        )];
    }

    /**
     * @return list<HealthCheck>
     */
    private function cron(): array
    {
        $heartbeat = $this->state->get('cron.heartbeat');
        if (!is_array($heartbeat) || !is_string($heartbeat['at'] ?? null)) {
            return [new HealthCheck('Cron', 'Heartbeat', HealthCheck::ERROR, 'Cron has never run. Add: * * * * * php /path/to/app/bin/cron.php')];
        }
        $at = new DateTimeImmutable($heartbeat['at'], new DateTimeZone('UTC'));
        $age = $this->clock->now()->getTimestamp() - $at->getTimestamp();
        $interval = max(1, $this->cronIntervalMinutes) * 60;
        $status = self::heartbeatStatus($age, $this->cronIntervalMinutes);
        $cliPhp = is_string($heartbeat['php_version'] ?? null) ? $heartbeat['php_version'] : 'unknown';

        return [
            new HealthCheck('Cron', 'Heartbeat', $status, sprintf('Last run %s UTC (%s ago); expected every %d min (CRON_INTERVAL_MINUTES)', $heartbeat['at'], self::ago($age), (int) ($interval / 60))),
            new HealthCheck('Cron', 'CLI PHP version', version_compare($cliPhp, '8.5.0', '>=') ? HealthCheck::OK : HealthCheck::ERROR, $cliPhp),
        ];
    }

    /**
     * @return list<HealthCheck>
     */
    private function queueChecks(): array
    {
        $stats = $this->queue->stats();
        $oldest = $this->db->fetchValue("SELECT MIN(available_at) FROM jobs WHERE status = 'queued' AND available_at <= UTC_TIMESTAMP()");
        $backlog = is_string($oldest) ? $this->clock->now()->getTimestamp() - (new DateTimeImmutable($oldest, new DateTimeZone('UTC')))->getTimestamp() : 0;
        $recentFailures = (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE status = 'failed' AND finished_at > UTC_TIMESTAMP() - INTERVAL 1 DAY");

        return [
            new HealthCheck('Queue', 'Jobs', HealthCheck::INFO, sprintf('%d queued, %d running, %d completed, %d failed', $stats['queued'], $stats['running'], $stats['completed'], $stats['failed'])),
            new HealthCheck('Queue', 'Oldest waiting job', $backlog <= 600 ? HealthCheck::OK : HealthCheck::WARNING, $backlog === 0 ? 'None waiting' : self::ago($backlog) . ' waiting'),
            new HealthCheck('Queue', 'Failures (24 h)', $recentFailures === 0 ? HealthCheck::OK : HealthCheck::WARNING, (string) $recentFailures),
        ];
    }

    /**
     * @return list<HealthCheck>
     */
    private function integrations(): array
    {
        return [
            new HealthCheck('Integrations', 'Google Search Console', HealthCheck::INFO, 'Connect under Integrations; imports run in the background via cron (see each project\'s Search performance page)'),
            new HealthCheck('Integrations', 'DataForSEO', HealthCheck::INFO, 'Connect per workspace under Integrations → DataForSEO; paid requests are limited by each workspace\'s budgets'),
            new HealthCheck('Integrations', 'MCP', HealthCheck::INFO, 'Endpoint /mcp with read access and opt-in write scopes; workspace owners and admins create tokens under Integrations → AI access'),
        ];
    }

    /**
     * OK within one interval plus 2 minutes of slack; a warning until three intervals
     * plus 10 minutes have passed; an error after that.
     */
    public static function heartbeatStatus(int $ageSeconds, int $intervalMinutes): string
    {
        $interval = max(1, $intervalMinutes) * 60;

        return $ageSeconds <= $interval + 120 ? HealthCheck::OK : ($ageSeconds <= 3 * $interval + 600 ? HealthCheck::WARNING : HealthCheck::ERROR);
    }

    private static function ago(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds . ' s',
            $seconds < 7200 => intdiv($seconds, 60) . ' min',
            $seconds < 172800 => intdiv($seconds, 3600) . ' h',
            default => intdiv($seconds, 86400) . ' days',
        };
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System\Support;

use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Modules\System\Health\HealthCheck;
use SeoControlCenter\Modules\System\Health\SystemHealth;
use ZipArchive;

/**
 * A ZIP for troubleshooting (DEPLOYMENT.md -> support bundle): health
 * checks, versions, migrations, queue state, allowlisted settings and the
 * recent application log. Never contains secrets, the .env file, the key
 * ring, customer SEO data or e-mail addresses.
 */
final readonly class SupportBundle
{
    /** Settings that are safe to share; everything else is left out. */
    private const array SETTINGS = [
        'app.env', 'app.debug', 'app.url', 'app.timezone',
        'queue.cron_interval_minutes', 'queue.cron_max_seconds', 'queue.worker_slots', 'queue.lease_seconds', 'queue.memory_limit_mb',
        'session.secure', 'session.idle_timeout', 'session.absolute_lifetime',
        'security.hsts.enabled', 'logging.level', 'backup.keep',
        'search_console.backfill_days', 'search_console.max_rows_per_day', 'search_console.totals_refresh_hours',
        'dataforseo.sandbox', 'dataforseo.raw_response_days',
    ];
    private const int LOG_FILES = 3;
    private const int LOG_LINES = 300;

    public function __construct(
        private SystemHealth $health,
        private Migrator $migrator,
        private Queue $queue,
        private Connection $db,
        private Config $config,
        private Paths $paths,
        private Clock $clock,
        private string $appVersion,
    ) {}

    /**
     * Builds the bundle in a temporary file; the caller deletes it.
     *
     * @return array{path: string, name: string}
     */
    public function build(): array
    {
        $name = 'scc-support-' . $this->clock->now()->format('Ymd-His') . '.zip';
        // storage/tmp, not the system temp directory: open_basedir often excludes /tmp.
        if (!is_dir($this->paths->storage('tmp'))) {
            @mkdir($this->paths->storage('tmp'), 0o750, true);
        }
        $path = $this->paths->storage('tmp') . '/support-' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The support bundle cannot be created.');
        }

        $zip->addFromString('README.txt', "SEO Control Center support bundle\nGenerated " . $this->clock->now()->format('Y-m-d H:i:s') . " UTC.\nContains no passwords, keys, tokens, e-mail addresses or SEO data.\n");
        $zip->addFromString('environment.json', self::json([
            'app_version' => $this->appVersion,
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
            'database' => $this->safe(fn(): string => $this->db->serverVersion()->raw),
            'extensions' => get_loaded_extensions(),
            'ini' => array_map(ini_get(...), ['memory_limit' => 'memory_limit', 'max_execution_time' => 'max_execution_time', 'upload_max_filesize' => 'upload_max_filesize', 'post_max_size' => 'post_max_size', 'opcache.enable' => 'opcache.enable']),
        ]));
        $zip->addFromString('health.json', self::json(array_map(
            static fn(HealthCheck $c): array => ['group' => $c->group, 'name' => $c->name, 'status' => $c->status, 'detail' => $c->detail],
            $this->health->run(),
        )));
        $zip->addFromString('migrations.json', self::json([
            'pending' => $this->safe(fn(): array => array_map(static fn($f): string => $f->name, $this->migrator->pending())),
        ]));
        $zip->addFromString('queue.json', self::json([
            'stats' => $this->safe(fn(): array => $this->queue->stats()),
            'recent_failures' => $this->safe(fn(): array => array_map(
                static fn(array $r): array => ['type' => $r['type'], 'attempts' => $r['attempts'], 'finished_at' => $r['finished_at'], 'error' => self::scrub(mb_substr((string) $r['last_error'], 0, 500))],
                $this->db->fetchAll("SELECT type, attempts, finished_at, last_error FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT 20"),
            )),
        ]));
        $settings = [];
        foreach (self::SETTINGS as $key) {
            $settings[$key] = $this->config->get($key);
        }
        $zip->addFromString('settings.json', self::json($settings));

        $logs = glob($this->paths->storage('logs') . '/app-*.log') ?: [];
        rsort($logs);
        foreach (array_slice($logs, 0, self::LOG_FILES) as $log) {
            $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
            $zip->addFromString('logs/' . basename($log), self::scrub(implode("\n", array_slice($lines, -self::LOG_LINES))) . "\n");
        }

        if (!$zip->close()) {
            throw new \RuntimeException('The support bundle cannot be written.');
        }

        return ['path' => $path, 'name' => $name];
    }

    /**
     * Removes values that must never leave the server even if a log line contains them.
     */
    public static function scrub(string $text): string
    {
        return (string) preg_replace(
            ['/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', '/scc_[A-Za-z0-9_-]{20,}/'],
            ['[email]', 'Bearer [redacted]', 'scc_[redacted]'],
            $text,
        );
    }

    /**
     * @param \Closure(): mixed $read
     */
    private function safe(\Closure $read): mixed
    {
        try {
            return $read();
        } catch (\Throwable $e) {
            return 'unavailable: ' . $e::class;
        }
    }

    private static function json(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}

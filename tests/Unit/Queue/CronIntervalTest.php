<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Config\Environment;
use SeoControlCenter\Modules\System\Health\HealthCheck;
use SeoControlCenter\Modules\System\Health\SystemHealth;

/**
 * CRON_INTERVAL_MINUTES: worker time per run and the heartbeat thresholds.
 */
final class CronIntervalTest extends TestCase
{
    public function testWorkerTimeFollowsTheInterval(): void
    {
        self::assertSame(1, $this->config([])->int('queue.cron_interval_minutes'));
        self::assertSame(50, $this->config([])->int('queue.cron_max_seconds'));
        self::assertSame(5, $this->config(['CRON_INTERVAL_MINUTES' => '5'])->int('queue.cron_interval_minutes'));
        self::assertSame(240, $this->config(['CRON_INTERVAL_MINUTES' => '5'])->int('queue.cron_max_seconds'));
        self::assertSame(60, $this->config(['CRON_INTERVAL_MINUTES' => '2'])->int('queue.cron_max_seconds'));
        self::assertSame(100, $this->config(['CRON_INTERVAL_MINUTES' => '5', 'QUEUE_CRON_MAX_SECONDS' => '100'])->int('queue.cron_max_seconds'), 'An explicit value wins.');
        self::assertSame(60, $this->config(['CRON_INTERVAL_MINUTES' => '500'])->int('queue.cron_interval_minutes'), 'Capped at an hour.');
    }

    public function testHeartbeatThresholds(): void
    {
        self::assertSame(HealthCheck::OK, SystemHealth::heartbeatStatus(170, 1));
        self::assertSame(HealthCheck::WARNING, SystemHealth::heartbeatStatus(200, 1));
        self::assertSame(HealthCheck::ERROR, SystemHealth::heartbeatStatus(900, 1));

        // Every 5 minutes: a run 5.5 minutes ago is normal, not a warning.
        self::assertSame(HealthCheck::OK, SystemHealth::heartbeatStatus(330, 5));
        self::assertSame(HealthCheck::WARNING, SystemHealth::heartbeatStatus(600, 5));
        self::assertSame(HealthCheck::ERROR, SystemHealth::heartbeatStatus(1600, 5));
    }

    /**
     * @param array<string, string> $env
     */
    private function config(array $env): Config
    {
        return Config::fromDirectory(dirname(__DIR__, 3) . '/config', new Environment(['APP_ENV' => 'testing', ...$env]));
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests\Setup;

use SeoControlCenter\Domain\Quests\SetupStatus;
use SeoControlCenter\Modules\System\Health\HealthCheck;
use SeoControlCenter\Modules\System\Health\SystemHealth;

/** Foundations status of the "Keep background tasks running" step. */
final class CronHeartbeat
{
    private function __construct() {}

    /** Heartbeats are untrusted persisted state; only cron's exact UTC format is accepted. */
    public static function status(mixed $heartbeat, \DateTimeImmutable $now, int $intervalMinutes): string
    {
        if (!is_array($heartbeat) || !is_string($heartbeat['at'] ?? null)) {
            return SetupStatus::NEEDS_SETUP;
        }
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $heartbeat['at'], new \DateTimeZone('UTC'));
        if ($at === false || $at->format('Y-m-d H:i:s') !== $heartbeat['at']) {
            return SetupStatus::NEEDS_ATTENTION;
        }
        $age = $now->getTimestamp() - $at->getTimestamp();

        return $age >= 0 && SystemHealth::heartbeatStatus($age, $intervalMinutes) === HealthCheck::OK ? SetupStatus::READY : SetupStatus::NEEDS_ATTENTION;
    }
}

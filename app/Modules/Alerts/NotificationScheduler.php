<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

/**
 * Queues the e-mails: one job per alert and one per weekly digest.
 */
interface NotificationScheduler
{
    public function sendAlert(int $alertId, int $workspaceId): void;

    public function sendWebhook(int $alertId, int $workspaceId, string $version): void;

    public function sendDigest(int $userId, int $workspaceId, string $week): void;
}

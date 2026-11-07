<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Jobs;

use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Modules\Alerts\NotificationScheduler;

/**
 * Job types of alerts and digests, and the queue side of NotificationScheduler.
 */
final readonly class NotificationJobs implements NotificationScheduler
{
    public const string SCAN = 'alerts.scan';
    public const string SEND = 'alerts.send';
    public const string WEBHOOK = 'alerts.webhook';
    public const string DIGESTS = 'digest.dispatch';
    public const string DIGEST = 'digest.send';

    public function __construct(private Queue $queue) {}

    public function sendAlert(int $alertId, int $workspaceId): void
    {
        $this->queue->dispatch(self::SEND, ['alert' => $alertId], idempotencyKey: 'alerts.send:' . $alertId, workspaceId: $workspaceId);
    }

    public function sendDigest(int $userId, int $workspaceId, string $week): void
    {
        $this->queue->dispatch(self::DIGEST, ['user' => $userId, 'workspace' => $workspaceId, 'week' => $week], idempotencyKey: sprintf('digest.send:%d:%d:%s', $userId, $workspaceId, $week), workspaceId: $workspaceId);
    }

    public function sendWebhook(int $alertId, int $workspaceId, string $version): void
    {
        $this->queue->dispatch(self::WEBHOOK, ['alert' => $alertId, 'version' => $version], maxAttempts: 1, idempotencyKey: 'alerts.webhook:' . $alertId . ':' . $version, workspaceId: $workspaceId);
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Audit;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Logging\Redactor;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use Throwable;

/**
 * Writes audit records to `audit_logs`. Metadata passes through the log
 * redactor. A failure to audit is logged as critical but never shown to users.
 */
final readonly class PdoAuditLogger implements AuditLogger
{
    public function __construct(
        private Connection $db,
        private Redactor $redactor,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function record(AuditEvent $event, ?ClientInfo $client = null): void
    {
        try {
            $metadata = $event->metadata === []
                ? null
                : json_encode($this->redactor->redactArray($event->metadata), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $this->db->execute(
                'INSERT INTO audit_logs
                    (workspace_id, actor_type, actor_user_id, actor_label, action, target_type, target_id,
                     outcome, ip_address, user_agent, request_id, metadata, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $event->workspaceId,
                    $event->actorType->value,
                    $event->actorUserId,
                    $event->actorLabel !== null ? mb_substr($event->actorLabel, 0, 191) : null,
                    $event->action,
                    $event->targetType,
                    $event->targetId,
                    $event->success ? 'success' : 'failure',
                    $client?->ip,
                    $client?->userAgent,
                    $client?->requestId,
                    $metadata,
                    $this->clock->now()->format('Y-m-d H:i:s'),
                ],
            );
        } catch (Throwable $e) {
            $this->logger->critical('Failed to write audit record {action}', ['action' => $event->action, 'exception' => $e]);
        }
    }
}

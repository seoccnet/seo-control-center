<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Jobs;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\InternalLinks\LinkGraphRepository;
use SeoControlCenter\Modules\SiteAudit\LinkSuggestionScheduler;
use SeoControlCenter\Modules\SiteAudit\LinkSuggestionService;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;

/**
 * Queues and builds internal link suggestions (ADR-040, F1): once when an
 * audit completes, and daily for every project's newest completed audit so
 * changed topic owners show up without a new crawl.
 */
final readonly class LinkSuggestionJobs implements LinkSuggestionScheduler, JobHandler
{
    public const string BUILD = 'site_audit.link_suggestions';
    public const string DAILY = 'site_audit.link_suggestions_daily';

    public function __construct(private LinkGraphRepository $graph, private Queue $queue, private Clock $clock, private ?LinkSuggestionService $service = null) {}

    public function auditCompleted(SiteAudit $audit): void
    {
        $this->graph->pruneText($audit->projectId, $audit->id);
        $this->dispatch($audit->id, $audit->workspaceId, 'done');
    }

    public function handle(array $payload, JobContext $context): void
    {
        if ($context->type === self::DAILY) {
            foreach ($this->graph->latestCompleted() as $row) {
                $this->dispatch($row['audit_id'], $row['workspace_id'], $this->clock->now()->format('Ymd'));
            }

            return;
        }
        $auditId = $payload['audit_id'] ?? null;
        if (!is_int($auditId) || $auditId < 1) {
            throw new PermanentJobException('Invalid audit_id.');
        }
        $this->service?->build($auditId);
    }

    private function dispatch(int $auditId, int $workspaceId, string $when): void
    {
        $this->queue->dispatch(self::BUILD, ['audit_id' => $auditId, 'workspace_id' => $workspaceId], SiteAuditJobs::QUEUE, idempotencyKey: 'link-suggestions:' . $auditId . ':' . $when, workspaceId: $workspaceId);
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ArticleIdeas;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository;

/**
 * article_ideas.run buys one run's competitor rows and builds the ideas;
 * article_ideas.rebuild rebuilds every project's ideas daily from the rows
 * it already has (free), or one project's on request; article_ideas.outline
 * reads one idea's competitor outlines (free); topic_plans.links builds a
 * topic plan's link map (free) and topic_plans.backlinks buys its backlink
 * check (paid, confirmed); article_ideas.track measures published articles
 * daily from stored Search Console data (free). ADR-041, G1–G3.
 */
final readonly class ArticleIdeaJobs implements ArticleIdeaScheduler, JobHandler
{
    public const string RUN = 'article_ideas.run';
    public const string REBUILD = 'article_ideas.rebuild';
    public const string OUTLINE = 'article_ideas.outline';
    public const string PLAN_LINKS = 'topic_plans.links';
    public const string PLAN_BACKLINKS = 'topic_plans.backlinks';
    public const string TRACK = 'article_ideas.track';
    public const string QUEUE = 'analysis';

    public function __construct(private Queue $queue, private ?ArticleIdeaService $service = null, private ?ArticleIdeaRepository $ideas = null, private ?TopicPlanService $plans = null, private ?ArticleTrackingService $tracking = null) {}

    public function queue(int $runId, int $workspaceId): void
    {
        // Paid: never retried automatically.
        $this->queue->dispatch(self::RUN, ['run_id' => $runId, 'workspace_id' => $workspaceId], self::QUEUE, maxAttempts: 1, idempotencyKey: 'article-ideas:' . $runId, workspaceId: $workspaceId);
    }

    public function rebuild(int $projectId, int $workspaceId, string $window): void
    {
        $this->queue->dispatch(self::REBUILD, ['project_id' => $projectId, 'workspace_id' => $workspaceId], self::QUEUE, idempotencyKey: 'article-ideas-rebuild:' . $projectId . ':' . $window, workspaceId: $workspaceId);
    }

    public function queueOutline(int $projectId, int $workspaceId, string $key): void
    {
        $this->queue->dispatch(self::OUTLINE, ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'idea_key' => $key], self::QUEUE, maxAttempts: 2, workspaceId: $workspaceId);
    }

    public function queuePlanLinks(int $planId, int $workspaceId): void
    {
        $this->queue->dispatch(self::PLAN_LINKS, ['plan_id' => $planId, 'workspace_id' => $workspaceId], self::QUEUE, maxAttempts: 2, workspaceId: $workspaceId);
    }

    public function queuePlanBacklinks(int $planId, int $workspaceId): void
    {
        // Paid: never retried automatically.
        $this->queue->dispatch(self::PLAN_BACKLINKS, ['plan_id' => $planId, 'workspace_id' => $workspaceId], self::QUEUE, maxAttempts: 1, workspaceId: $workspaceId);
    }

    public function handle(array $payload, JobContext $context): void
    {
        if ($context->type === self::TRACK) {
            $this->tracking?->checkAll($context->renewLease(...));

            return;
        }
        if (in_array($context->type, [self::PLAN_LINKS, self::PLAN_BACKLINKS], true)) {
            $planId = $payload['plan_id'] ?? null;
            if (!is_int($planId) || $planId < 1) {
                throw new PermanentJobException('Invalid topic plan.');
            }
            $context->type === self::PLAN_LINKS ? $this->plans?->buildLinks($planId) : $this->plans?->checkBacklinks($planId);

            return;
        }
        if ($this->service === null) {
            return;
        }
        if ($context->type === self::OUTLINE) {
            $projectId = $payload['project_id'] ?? null;
            $workspaceId = $payload['workspace_id'] ?? null;
            $key = $payload['idea_key'] ?? null;
            if (!is_int($projectId) || !is_int($workspaceId) || !is_string($key) || preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
                throw new PermanentJobException('Invalid article idea outline.');
            }
            $this->service->readOutline($projectId, $workspaceId, $key, $context->renewLease(...));

            return;
        }
        if ($context->type === self::REBUILD) {
            $projectId = $payload['project_id'] ?? null;
            $workspaceId = $payload['workspace_id'] ?? null;
            if (is_int($projectId) && is_int($workspaceId)) {
                $this->service->rebuild($projectId, $workspaceId);

                return;
            }
            foreach ($this->ideas?->rebuildable(200) ?? [] as $row) {
                $this->service->rebuild($row['project_id'], $row['workspace_id']);
                if (!$context->renewLease()) {
                    return;
                }
            }

            return;
        }
        $runId = $payload['run_id'] ?? null;
        if (!is_int($runId) || $runId < 1) {
            throw new PermanentJobException('Invalid article idea run.');
        }
        $this->service->execute($runId);
    }
}

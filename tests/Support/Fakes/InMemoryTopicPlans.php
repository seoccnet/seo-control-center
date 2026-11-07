<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository;
use SeoControlCenter\Domain\Projects\Project;

/** Topic plans in memory (ADR-041, G2). */
final class InMemoryTopicPlans implements TopicPlanRepository
{
    /** @var array<int, TopicPlan> */
    public array $plans = [];

    private int $nextId = 1;

    public function __construct(private readonly Clock $clock) {}

    public function create(Project $project, string $pillarKey, string $pillarKeyword, array $members, ?int $userId, string $now): TopicPlan
    {
        $existing = $this->forPillar($project, $pillarKey);
        if ($existing !== null) {
            return $existing;
        }
        $id = $this->nextId++;

        return $this->plans[$id] = new TopicPlan($id, Uuid::v7($this->clock), $project->id, $project->workspaceId, $pillarKey, $pillarKeyword, $members, null, null, null, null, null, null, null, 0.0, $now, $now);
    }

    public function find(Project $project, string $publicId): ?TopicPlan
    {
        foreach ($this->plans as $plan) {
            if ($plan->publicId === $publicId && $plan->projectId === $project->id && $plan->workspaceId === $project->workspaceId) {
                return $plan;
            }
        }

        return null;
    }

    public function byId(int $id): ?TopicPlan
    {
        return $this->plans[$id] ?? null;
    }

    public function forPillar(Project $project, string $pillarKey): ?TopicPlan
    {
        foreach ($this->plans as $plan) {
            if ($plan->pillarKey === $pillarKey && $plan->projectId === $project->id && $plan->workspaceId === $project->workspaceId) {
                return $plan;
            }
        }

        return null;
    }

    public function forProject(Project $project, int $limit): array
    {
        $plans = array_values(array_filter($this->plans, static fn(TopicPlan $p): bool => $p->projectId === $project->id && $p->workspaceId === $project->workspaceId));

        return array_slice(array_reverse($plans), 0, max(1, $limit));
    }

    public function saveMembers(int $planId, array $members, string $now): void
    {
        $this->with($planId, members: $members);
    }

    public function queueLinks(int $planId, string $now): bool
    {
        if (($this->plans[$planId] ?? null)?->linksStatus === TopicPlan::QUEUED) {
            return false;
        }
        $this->with($planId, linksStatus: TopicPlan::QUEUED);

        return isset($this->plans[$planId]);
    }

    public function saveLinks(int $planId, array $links, string $now): void
    {
        $this->with($planId, links: $links, linksStatus: TopicPlan::DONE, linksBuiltAt: $now);
    }

    public function queueBacklinks(int $planId, float $cost, string $now): bool
    {
        if (($this->plans[$planId] ?? null)?->backlinksStatus === TopicPlan::QUEUED) {
            return false;
        }
        $this->with($planId, backlinksStatus: TopicPlan::QUEUED, clearError: true, backlinksCost: $cost);

        return isset($this->plans[$planId]);
    }

    public function saveBacklinks(int $planId, array $pages, string $now): void
    {
        if (($this->plans[$planId] ?? null)?->backlinksStatus === TopicPlan::QUEUED) {
            $this->with($planId, backlinks: $pages, backlinksStatus: TopicPlan::DONE, backlinksAt: $now);
        }
    }

    public function failBacklinks(int $planId, string $error, string $now): void
    {
        if (($this->plans[$planId] ?? null)?->backlinksStatus === TopicPlan::QUEUED) {
            $this->with($planId, backlinksStatus: TopicPlan::FAILED, backlinksError: $error, backlinksAt: $now);
        }
    }

    public function delete(Project $project, int $planId): void
    {
        $plan = $this->plans[$planId] ?? null;
        if ($plan !== null && $plan->projectId === $project->id && $plan->workspaceId === $project->workspaceId) {
            unset($this->plans[$planId]);
        }
    }

    /**
     * @param list<array{type: string, key: string, label: string, volume: int, kept: bool, url: ?string}>|null $members
     * @param array{internal: list<array{from: string, to: string, anchor: string}>, pages: list<array{source_url: string, to: string, anchor: string, paragraph: string}>, audit: ?string}|null $links
     * @param list<array{url: string, referring_domains: ?int, backlinks: ?int, rank: ?int}>|null $backlinks
     */
    private function with(int $planId, ?array $members = null, ?array $links = null, ?string $linksStatus = null, ?string $linksBuiltAt = null, ?array $backlinks = null, ?string $backlinksStatus = null, ?string $backlinksError = null, bool $clearError = false, ?string $backlinksAt = null, ?float $backlinksCost = null): void
    {
        $p = $this->plans[$planId] ?? null;
        if ($p === null) {
            return;
        }
        $this->plans[$planId] = new TopicPlan(
            $p->id,
            $p->publicId,
            $p->projectId,
            $p->workspaceId,
            $p->pillarKey,
            $p->pillarKeyword,
            $members ?? $p->members,
            $links ?? $p->links,
            $linksStatus ?? $p->linksStatus,
            $linksBuiltAt ?? $p->linksBuiltAt,
            $backlinks ?? $p->backlinks,
            $backlinksStatus ?? $p->backlinksStatus,
            $clearError ? null : ($backlinksError ?? $p->backlinksError),
            $backlinksAt ?? $p->backlinksAt,
            $backlinksCost ?? $p->backlinksCost,
            $p->createdAt,
            $p->updatedAt,
        );
    }
}

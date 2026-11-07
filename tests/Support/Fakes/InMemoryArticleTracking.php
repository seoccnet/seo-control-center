<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTracking;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTrackingRepository;
use SeoControlCenter\Domain\Projects\Project;

/** Published articles from ideas in memory (ADR-041, G3). */
final class InMemoryArticleTracking implements ArticleTrackingRepository
{
    /** @var array<int, ArticleTracking> */
    public array $trackings = [];

    private int $nextId = 1;

    public function __construct(private readonly Clock $clock) {}

    public function track(Project $project, ArticleIdea $idea, array $keywords, array $prediction, string $url, string $publishedOn, string $source, ?int $userId, string $now): ArticleTracking
    {
        $existing = null;
        foreach ($this->trackings as $t) {
            if ($t->projectId === $project->id && $t->workspaceId === $project->workspaceId && $t->ideaKey === $idea->key) {
                $existing = $t;
            }
        }
        $id = $existing->id ?? $this->nextId++;

        return $this->trackings[$id] = new ArticleTracking(
            $id,
            $existing->publicId ?? Uuid::v7($this->clock),
            $project->id,
            $project->workspaceId,
            $idea->key,
            $existing->mainKeyword ?? $idea->mainKeyword,
            $existing->keywords ?? $keywords,
            $existing->kind ?? $idea->kind,
            $existing->level ?? $idea->prediction['difficulty']['level'],
            $existing->prediction ?? $prediction,
            $url,
            $publishedOn,
            $source,
            null,
            null,
            $existing->createdAt ?? $now,
        );
    }

    public function forProject(Project $project, int $limit): array
    {
        $list = array_values(array_filter($this->trackings, static fn(ArticleTracking $t): bool => $t->projectId === $project->id && $t->workspaceId === $project->workspaceId));
        usort($list, static fn(ArticleTracking $a, ArticleTracking $b): int => [$b->publishedOn, $b->id] <=> [$a->publishedOn, $a->id]);

        return array_slice($list, 0, max(1, $limit));
    }

    public function find(Project $project, string $publicId): ?ArticleTracking
    {
        foreach ($this->trackings as $t) {
            if ($t->publicId === $publicId && $t->projectId === $project->id && $t->workspaceId === $project->workspaceId) {
                return $t;
            }
        }

        return null;
    }

    public function due(int $limit): array
    {
        return array_slice(array_values($this->trackings), 0, max(1, $limit));
    }

    public function saveResult(int $id, array $result, string $now): void
    {
        $t = $this->trackings[$id] ?? null;
        if ($t !== null) {
            $this->trackings[$id] = new ArticleTracking($t->id, $t->publicId, $t->projectId, $t->workspaceId, $t->ideaKey, $t->mainKeyword, $t->keywords, $t->kind, $t->level, $t->prediction, $t->url, $t->publishedOn, $t->source, $result, $now, $t->createdAt);
        }
    }

    public function delete(Project $project, int $id): void
    {
        $t = $this->trackings[$id] ?? null;
        if ($t !== null && $t->projectId === $project->id && $t->workspaceId === $project->workspaceId) {
            unset($this->trackings[$id]);
        }
    }
}

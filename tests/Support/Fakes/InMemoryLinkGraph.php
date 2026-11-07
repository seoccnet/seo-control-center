<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\InternalLinks\LinkGraphRepository;
use SeoControlCenter\Modules\SiteAudit\LinkSuggestionScheduler;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;

/**
 * The link graph and its suggestions in memory (ADR-040, F1). Tests fill
 * pages, paragraphs, links and suggestions per audit directly; completed
 * audits are recorded instead of queueing a build.
 */
final class InMemoryLinkGraph implements LinkGraphRepository, LinkSuggestionScheduler
{
    /** @var array<int, array<int, array{url: string, inbound: int}>> */
    public array $pages = [];
    /** @var array<int, list<array{page_id: int, text: string}>> */
    public array $paragraphs = [];
    /** @var array<int, list<array{from: int, to: int, anchor: ?string, place: ?string, context: ?string, class?: ?string, confidence?: ?string}>> */
    public array $links = [];
    /** @var array<int, array{project_id: int, rows: list<array{source_page_id: int, target_page_id: int, cluster_id: ?string, topic: string, anchor: string, paragraph: string, score: float}>}> */
    public array $suggestions = [];
    /** @var list<int> */
    public array $completed = [];
    /** @var array<int, string> */
    public array $built = [];
    /** @var list<array{audit_id: int, project_id: int, workspace_id: int}> */
    public array $latest = [];

    public function auditCompleted(SiteAudit $audit): void
    {
        $this->completed[] = $audit->id;
    }

    public function pruneText(int $projectId, int $keepAuditId): void
    {
        foreach (array_keys($this->suggestions) as $auditId) {
            if ($auditId !== $keepAuditId && $this->suggestions[$auditId]['project_id'] === $projectId) {
                unset($this->suggestions[$auditId], $this->paragraphs[$auditId]);
            }
        }
    }

    public function hasLinkText(int $auditId): bool
    {
        foreach ($this->links[$auditId] ?? [] as $link) {
            if ($link['place'] !== null) {
                return true;
            }
        }

        return isset($this->suggestions[$auditId]) || isset($this->paragraphs[$auditId]);
    }

    public function latestCompleted(): array
    {
        return $this->latest;
    }

    public function graphPages(int $auditId): array
    {
        return $this->pages[$auditId] ?? [];
    }

    public function paragraphs(int $auditId): array
    {
        return $this->paragraphs[$auditId] ?? [];
    }

    public function linked(int $auditId): array
    {
        $linked = [];
        foreach ($this->links[$auditId] ?? [] as $link) {
            if ($link['place'] === 'body' && !in_array($link['class'] ?? null, \SeoControlCenter\Domain\InternalLinks\LinkClass::NOT_TEXT, true)) {
                $linked[$link['from'] . ':' . $link['to']] = true;
            }
        }

        return $linked;
    }

    public function builtAt(int $auditId): ?string
    {
        return $this->built[$auditId] ?? null;
    }

    /** @var array<int, int> */
    public array $leftOut = [];

    public function leftOut(int $auditId): ?int
    {
        return $this->leftOut[$auditId] ?? null;
    }

    public function replaceSuggestions(int $auditId, int $projectId, array $rows, string $now, int $leftOut = 0): void
    {
        $this->suggestions[$auditId] = ['project_id' => $projectId, 'rows' => $rows];
        $this->built[$auditId] = $now;
        $this->leftOut[$auditId] = $leftOut;
    }

    public function suggestions(int $projectId, int $auditId, string $search, int $offset, int $limit): array
    {
        return array_slice($this->matching($projectId, $auditId, $search), $offset, $limit);
    }

    public function countSuggestions(int $projectId, int $auditId, string $search): int
    {
        return count($this->matching($projectId, $auditId, $search));
    }

    public function pageLinks(int $auditId, int $pageId, string $direction, int $limit): array
    {
        $out = [];
        foreach ($this->links[$auditId] ?? [] as $link) {
            if (($direction === 'in' ? $link['to'] : $link['from']) === $pageId) {
                $other = $direction === 'in' ? $link['from'] : $link['to'];
                $out[] = ['url' => $this->pages[$auditId][$other]['url'] ?? 'https://unknown.test/', 'anchor' => $link['anchor'], 'place' => $link['place'], 'class' => $link['class'] ?? null, 'confidence' => $link['confidence'] ?? null, 'context' => $link['context']];
            }
        }

        // Body links first, then by address, as the database orders them.
        usort($out, static fn(array $a, array $b): int => [$b['place'] === 'body', $a['url']] <=> [$a['place'] === 'body', $b['url']]);

        return array_slice($out, 0, $limit);
    }

    /** @return list<array{source_url: string, target_url: string, cluster_id: ?string, topic: string, anchor: string, paragraph: string, score: float}> */
    private function matching(int $projectId, int $auditId, string $search): array
    {
        $entry = $this->suggestions[$auditId] ?? null;
        if ($entry === null || $entry['project_id'] !== $projectId) {
            return [];
        }
        $rows = [];
        foreach ($entry['rows'] as $row) {
            $source = $this->pages[$auditId][$row['source_page_id']]['url'] ?? '';
            $target = $this->pages[$auditId][$row['target_page_id']]['url'] ?? '';
            if ($search === '' || str_contains($row['topic'], $search) || str_contains($source, $search) || str_contains($target, $search)) {
                $rows[] = ['source_url' => $source, 'target_url' => $target, 'cluster_id' => $row['cluster_id'], 'topic' => $row['topic'], 'anchor' => $row['anchor'], 'paragraph' => $row['paragraph'], 'score' => $row['score']];
            }
        }
        usort($rows, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return $rows;
    }
}

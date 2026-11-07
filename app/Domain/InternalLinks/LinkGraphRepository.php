<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\InternalLinks;

/**
 * The crawled internal link graph with its context and the link suggestions
 * built from it (ADR-040, F1). Every read is bound to one audit of one project.
 */
interface LinkGraphRepository
{
    /**
     * Drops paragraphs, link paragraphs and suggestions of the project's other
     * audits, except audits still running; anchors and places stay with the links.
     */
    public function pruneText(int $projectId, int $keepAuditId): void;

    /** Whether the audit recorded link places and text (audits crawled before 0.25.0 did not). */
    public function hasLinkText(int $auditId): bool;

    /**
     * The newest completed audit of every active project.
     *
     * @return list<array{audit_id: int, project_id: int, workspace_id: int}>
     */
    public function latestCompleted(): array;

    /**
     * Crawled indexable HTML pages (status 200, no noindex) with their inbound internal link count and title.
     *
     * @return array<int, array{url: string, inbound: int, title?: ?string}>
     */
    public function graphPages(int $auditId): array;

    /** @return list<array{page_id: int, text: string}> */
    public function paragraphs(int $auditId): array;

    /**
     * Existing links in the body text as "from:to" page IDs. Menu, header, footer
     * and sidebar links, related-article cards and product cards do not replace
     * a link in the text, so they are left out (0.35.0).
     *
     * @return array<string, true>
     */
    public function linked(int $auditId): array;

    /** When the audit's suggestions were last built (UTC), or null when they never were. */
    public function builtAt(int $auditId): ?string;

    /** How many matches the last build left out because the paragraph is about another intent (0.35.0), or null before. */
    public function leftOut(int $auditId): ?int;

    /**
     * @param list<array{source_page_id: int, target_page_id: int, cluster_id: ?string, topic: string, anchor: string, paragraph: string, score: float}> $rows
     */
    public function replaceSuggestions(int $auditId, int $projectId, array $rows, string $now, int $leftOut = 0): void;

    /**
     * Suggestions of one audit, best first; $search matches the topic or either page address.
     *
     * @return list<array{source_url: string, target_url: string, cluster_id: ?string, topic: string, anchor: string, paragraph: string, score: float}>
     */
    public function suggestions(int $projectId, int $auditId, string $search, int $offset, int $limit): array;

    public function countSuggestions(int $projectId, int $auditId, string $search): int;

    /**
     * Links into ("in") or out of ("out") one crawled page, body links first.
     *
     * @return list<array{url: string, anchor: ?string, place: ?string, class: ?string, confidence: ?string, context: ?string}>
     */
    public function pageLinks(int $auditId, int $pageId, string $direction, int $limit): array;
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

use SeoControlCenter\Domain\Projects\Project;

/** Topic plans (ADR-041, G2), bound to their project's workspace. */
interface TopicPlanRepository
{
    /**
     * Creates the plan of a pillar, or returns the existing one (one plan per pillar).
     *
     * @param list<array{type: string, key: string, label: string, volume: int, kept: bool, url: ?string}> $members
     */
    public function create(Project $project, string $pillarKey, string $pillarKeyword, array $members, ?int $userId, string $now): TopicPlan;

    public function find(Project $project, string $publicId): ?TopicPlan;

    public function byId(int $id): ?TopicPlan;

    public function forPillar(Project $project, string $pillarKey): ?TopicPlan;

    /** @return list<TopicPlan> newest first */
    public function forProject(Project $project, int $limit): array;

    /** @param list<array{type: string, key: string, label: string, volume: int, kept: bool, url: ?string}> $members */
    public function saveMembers(int $planId, array $members, string $now): void;

    /** Marks the link map as queued; false when it already is. */
    public function queueLinks(int $planId, string $now): bool;

    /** @param array{internal: list<array{from: string, to: string, anchor: string}>, pages: list<array{source_url: string, to: string, anchor: string, paragraph: string}>, audit: ?string} $links */
    public function saveLinks(int $planId, array $links, string $now): void;

    /** Marks the paid backlink check as queued with its estimated cost; false when one is already queued. */
    public function queueBacklinks(int $planId, float $cost, string $now): bool;

    /** @param list<array{url: string, referring_domains: ?int, backlinks: ?int, rank: ?int}> $pages */
    public function saveBacklinks(int $planId, array $pages, string $now): void;

    public function failBacklinks(int $planId, string $error, string $now): void;

    public function delete(Project $project, int $planId): void;
}

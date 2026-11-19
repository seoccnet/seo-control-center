<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

use SeoControlCenter\Domain\Keywords\KeywordLocale;

/**
 * Rank tracking settings, snapshots and daily aggregates. Methods take the
 * project (and, where tenant data is joined, workspace) context; callers
 * must have authorized access to the project.
 */
interface RankingRepository
{
    /** Counts exact-day positions; with cohortDate, only keywords checked on both dates.
     * @return array<string, int>
     */
    public function positionBuckets(int $projectId, int $workspaceId, SerpDevice $device, string $date, ?string $cohortDate = null): array;

    public function settings(int $projectId): RankingSettings;

    public function saveSettings(int $projectId, RankingSettings $settings, int $userId): void;

    /**
     * Enabled, not deleted projects whose next check is due on $today.
     *
     * @return list<array{project_id: int, workspace_id: int}>
     */
    public function dueProjects(string $today): array;

    /**
     * Enabled, not deleted projects with rank tracking, whatever their schedule.
     *
     * @return list<array{project_id: int, workspace_id: int}>
     */
    public function trackedProjects(): array;

    /**
     * The domain to look for, or null when the project was deleted or is
     * not in the workspace (a queued check then spends nothing).
     */
    public function projectDomain(int $projectId, int $workspaceId): ?string;

    /**
     * Keywords of the project not yet checked on $date for the device.
     *
     * @return list<array{id: int, keyword: string, locale: KeywordLocale}>
     */
    public function unchecked(int $projectId, SerpDevice $device, string $date, int $limit): array;

    public function countUnchecked(int $projectId, SerpDevice $device, string $date): int;

    /**
     * Stores a snapshot; a second snapshot for the same keyword, device and day replaces the first.
     */
    public function storeSnapshot(RankSnapshot $snapshot): void;

    /**
     * Recomputes the daily aggregate (including visibility) from that day's snapshots.
     */
    public function aggregate(int $projectId, int $workspaceId, SerpDevice $device, string $date): void;

    /**
     * @return list<RankingDay> oldest first
     */
    public function days(int $projectId, SerpDevice $device, string $from, string $to): array;

    public function latestDay(int $projectId, SerpDevice $device): ?RankingDay;

    /**
     * The newest day on or before $date that has an aggregate.
     */
    public function dayOnOrBefore(int $projectId, SerpDevice $device, string $date): ?RankingDay;

    /**
     * @return list<RankedKeyword> ranking keywords first (best position first), then unranked, then unchecked;
     *                             with a filter only keywords whose latest check matches it
     */
    public function keywords(int $projectId, int $workspaceId, SerpDevice $device, int $limit, int $offset, ?PositionFilter $filter = null): array;

    public function countKeywords(int $projectId, SerpDevice $device, ?PositionFilter $filter = null): int;

    /**
     * Checks of one project keyword (by public ID) since $since, oldest first.
     *
     * @return list<RankCheck>
     */
    public function history(int $projectId, string $keywordPublicId, SerpDevice $device, string $since): array;

    /**
     * The latest title Google showed for the project's own result, per
     * keyword checked since $since (1.7.0); keywords without one are left out.
     *
     * @return list<array{url: string, title: string, keyword: string, checkedOn: string}>
     */
    public function shownTitles(int $projectId, SerpDevice $device, string $since, int $limit): array;

    /**
     * Every check of every keyword of the project since $since, oldest first,
     * with the keyword's search volume when known (1.8.0).
     *
     * @return array<string, array{keyword: string, volume: ?int, checks: list<array{date: string, position: ?int, url: ?string}>}> keyword public ID =>
     */
    public function urlHistory(int $projectId, int $workspaceId, SerpDevice $device, string $since): array;

    /**
     * Per check day since $since: how far checked (not imported) keywords
     * moved since their previous check on the device at most 8 days earlier,
     * positions beyond 20 counted as 21 and pairs outside the top 20 on both
     * days left out (1.7.0).
     *
     * @return list<array{date: string, pairs: int, moved: int, average: float}>
     */
    public function volatility(int $projectId, SerpDevice $device, string $since): array;

    /**
     * Keywords whose position changed between two check days.
     *
     * @return list<RankingMove>
     */
    public function moves(int $projectId, SerpDevice $device, string $from, string $to): array;

    /**
     * Every check of one day with the same keyword's previous check on the
     * same device, when that one is at most $maxGapDays older (ADR-039).
     * The keyword fixes the market, so the pair is always comparable.
     *
     * @return list<array{keyword_id: int, keyword_public_id: string, keyword: string, current: RankCheck, previous: ?RankCheck}>
     */
    public function comparableChecks(int $projectId, SerpDevice $device, string $date, int $maxGapDays): array;

    /**
     * Days with checks on or after $since, oldest first.
     *
     * @return list<string>
     */
    public function checkDays(int $projectId, SerpDevice $device, string $since): array;

    /**
     * Featured snippets and "People also ask" questions from each tracked
     * keyword's latest check in the last year (1.5.0), keywords with the
     * highest search volume first. Keywords whose latest result page had
     * neither are left out.
     *
     * @return list<SerpAnswers>
     */
    public function answers(int $projectId, int $workspaceId, SerpDevice $device, int $limit): array;

    public function syncState(int $projectId): ?RankingSyncState;

    public function setSyncState(int $projectId, string $status, ?string $error = null, ?string $checkedOn = null): void;
}

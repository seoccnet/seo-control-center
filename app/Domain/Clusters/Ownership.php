<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Clusters;

use SeoControlCenter\Domain\Keywords\Keyword;

/**
 * Which page owns a cluster (ADR-037). From the project's own data only:
 *
 * - Search Console: clicks (then impressions) of the cluster's searches per
 *   landing page over the analysis window. With at least 10 clicks the
 *   click share decides, otherwise the impression share.
 * - Without Search Console rows, the URL that tracked rank checks found
 *   most often for the cluster's keywords.
 *
 * The leader owns the cluster unless the runner-up is close: a share of at
 * least 20 % and at least 60 % of the leader's share makes it contested.
 * Under 5 clicks and 50 impressions in total there is too little data to
 * name an owner: unowned, with the leading page named in the reason.
 * No page with impressions or rankings: unowned.
 *
 * Pass page keys (PageKey::of) as URLs so that pagination variants of one
 * listing count as one page.
 */
final class Ownership
{
    public const float CONTESTED_SHARE = 0.2;
    public const float CONTESTED_RATIO = 0.6;
    private const int CLICK_BASIS = 10;
    public const int MIN_CLICKS = 5;
    public const int MIN_IMPRESSIONS = 50;

    /**
     * @param list<string> $members normalized keywords
     * @param list<array{query: string, url: string, clicks: int, impressions: int, position: float}> $queryPages
     * @param list<array{keyword: string, url: ?string, position: ?int}> $rankings
     * @return array{status: string, url: ?string, source: ?string, candidates: list<array{url: string, clicks: int, impressions: int, share: float, best_position: ?float}>, reason: string}
     */
    public static function infer(array $members, array $queryPages, array $rankings): array
    {
        $wanted = array_fill_keys($members, true);
        /** @var array<string, array{url: string, clicks: int, impressions: int, best_position: ?float}> $pages */
        $pages = [];
        foreach ($queryPages as $row) {
            if (!isset($wanted[Keyword::normalize($row['query'])])) {
                continue;
            }
            $page = $pages[$row['url']] ?? ['url' => $row['url'], 'clicks' => 0, 'impressions' => 0, 'best_position' => null];
            $page['clicks'] += $row['clicks'];
            $page['impressions'] += $row['impressions'];
            if ($row['impressions'] > 0 && ($page['best_position'] === null || $row['position'] < $page['best_position'])) {
                $page['best_position'] = round($row['position'], 1);
            }
            $pages[$row['url']] = $page;
        }
        $clicks = array_sum(array_column($pages, 'clicks'));
        $impressions = array_sum(array_column($pages, 'impressions'));
        if ($impressions > 0) {
            $byClicks = $clicks >= self::CLICK_BASIS;
            $candidates = array_map(static fn(array $p): array => [...$p, 'share' => round($byClicks ? $p['clicks'] / $clicks : $p['impressions'] / $impressions, 3)], array_values($pages));
            usort($candidates, static fn(array $a, array $b): int => [$b['share'], $b['clicks'], $b['impressions'], $a['url']] <=> [$a['share'], $a['clicks'], $a['impressions'], $b['url']]);
            $candidates = array_slice($candidates, 0, 5);
            $leader = $candidates[0];
            $second = $candidates[1] ?? null;
            $basis = $byClicks ? 'clicks' : 'impressions';
            if ($clicks < self::MIN_CLICKS && $impressions < self::MIN_IMPRESSIONS) {
                return ['status' => KeywordCluster::UNOWNED, 'url' => null, 'source' => null, 'candidates' => $candidates, 'reason' => sprintf('Too little Search Console data to name an owner: %d clicks and %d impressions in total. %s leads so far.', $clicks, $impressions, $leader['url'])];
            }
            if ($second !== null && $second['share'] >= self::CONTESTED_SHARE && $second['share'] >= $leader['share'] * self::CONTESTED_RATIO) {
                return ['status' => KeywordCluster::CONTESTED, 'url' => $leader['url'], 'source' => 'search_console', 'candidates' => $candidates, 'reason' => sprintf('Two pages share the %s: %d %% and %d %%. Decide which page should own this topic.', $basis, (int) round($leader['share'] * 100), (int) round($second['share'] * 100))];
            }

            return ['status' => KeywordCluster::OWNED, 'url' => $leader['url'], 'source' => 'search_console', 'candidates' => $candidates, 'reason' => sprintf('Gets %d %% of the %s for these searches in Search Console.', (int) round($leader['share'] * 100), $basis)];
        }
        $counts = [];
        $best = [];
        foreach ($rankings as $row) {
            if ($row['url'] === null || $row['position'] === null || !isset($wanted[Keyword::normalize($row['keyword'])])) {
                continue;
            }
            $counts[$row['url']] = ($counts[$row['url']] ?? 0) + 1;
            $best[$row['url']] = min($best[$row['url']] ?? PHP_INT_MAX, $row['position']);
        }
        if ($counts !== []) {
            arsort($counts);
            $total = array_sum($counts);
            $candidates = [];
            foreach (array_slice($counts, 0, 5, true) as $url => $count) {
                $candidates[] = ['url' => (string) $url, 'clicks' => 0, 'impressions' => 0, 'share' => round($count / $total, 3), 'best_position' => (float) $best[$url]];
            }

            return ['status' => KeywordCluster::OWNED, 'url' => $candidates[0]['url'], 'source' => 'rankings', 'candidates' => $candidates, 'reason' => 'No Search Console data for these searches; the URL rank checks found most often.'];
        }

        return ['status' => KeywordCluster::UNOWNED, 'url' => null, 'source' => null, 'candidates' => [], 'reason' => 'No page gets impressions or rankings for these searches yet. A new or improved page could own this topic.'];
    }
}

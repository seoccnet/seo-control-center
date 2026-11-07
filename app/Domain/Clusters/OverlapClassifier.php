<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Clusters;

use SeoControlCenter\Domain\Opportunities\QueryPatterns;

/**
 * Classifies a search that more than one page of the site appears for
 * (ADR-037). Overlap is common and often harmless, so each case names its
 * kind instead of calling everything cannibalization:
 *
 * - brand: a brand search where the home page is one of the pages. Harmless.
 * - stacked: the same page always leads and the other shows up in most of
 *   the same weeks, both in the top 10: two listings for one search. Harmless.
 * - switching: the leading page (most impressions) changes at least twice
 *   across the weeks. Google cannot decide which page answers the search:
 *   likely harmful.
 * - split: a stable leader, but another page takes a real share. Possibly
 *   harmful; check whether the pages answer different intents.
 *
 * A page counts when it has at least 10 % of the search's impressions; the
 * search needs at least 50 impressions and data in at least 3 weeks.
 */
final class OverlapClassifier
{
    public const float MIN_SHARE = 0.1;
    public const int MIN_IMPRESSIONS = 50;
    public const int MIN_WEEKS = 3;
    private const float STACKED_WEEKS = 0.7;

    /**
     * @param list<array{week: string, url: string, clicks: int, impressions: int, position: float}> $rows one search, weekly per page
     * @param list<string> $brandTerms normalized brand words
     * @return array{query: string, kind: string, harmful: ?bool, switches: int, weeks: int, pages: list<array{url: string, share: float, weeks_led: int, position: float}>, note: string}|null null when only one page counts
     */
    public static function classify(string $query, array $rows, array $brandTerms = []): ?array
    {
        $total = array_sum(array_column($rows, 'impressions'));
        if ($total < self::MIN_IMPRESSIONS) {
            return null;
        }
        /** @var array<string, array{url: string, impressions: int, weighted: float, weeks: array<string, int>}> $pages */
        $pages = [];
        $weeks = [];
        foreach ($rows as $row) {
            $page = $pages[$row['url']] ?? ['url' => $row['url'], 'impressions' => 0, 'weighted' => 0.0, 'weeks' => []];
            $page['impressions'] += $row['impressions'];
            $page['weighted'] += $row['position'] * $row['impressions'];
            $page['weeks'][$row['week']] = ($page['weeks'][$row['week']] ?? 0) + $row['impressions'];
            $pages[$row['url']] = $page;
            $weeks[$row['week']] = true;
        }
        $counted = array_values(array_filter($pages, static fn(array $p): bool => $p['impressions'] >= $total * self::MIN_SHARE));
        if (count($counted) < 2 || count($weeks) < self::MIN_WEEKS) {
            return null;
        }
        ksort($weeks);
        $leaders = [];
        foreach (array_keys($weeks) as $week) {
            $top = null;
            foreach ($counted as $page) {
                $value = $page['weeks'][$week] ?? 0;
                if ($value > 0 && ($top === null || $value > $top[1] || ($value === $top[1] && $page['url'] < $top[0]))) {
                    $top = [$page['url'], $value];
                }
            }
            if ($top !== null) {
                $leaders[] = $top[0];
            }
        }
        $switches = 0;
        for ($i = 1, $n = count($leaders); $i < $n; ++$i) {
            $switches += $leaders[$i] !== $leaders[$i - 1] ? 1 : 0;
        }
        $led = array_count_values($leaders);
        usort($counted, static fn(array $a, array $b): int => [$b['impressions'], $a['url']] <=> [$a['impressions'], $b['url']]);
        $summary = array_map(static fn(array $p): array => [
            'url' => $p['url'],
            'share' => round($p['impressions'] / $total, 3),
            'weeks_led' => $led[$p['url']] ?? 0,
            'position' => round($p['weighted'] / max(1, $p['impressions']), 1),
        ], array_slice($counted, 0, 4));
        $base = ['query' => $query, 'switches' => $switches, 'weeks' => count($weeks), 'pages' => $summary];

        if (array_filter($brandTerms, static fn(string $t): bool => $t !== '' && QueryPatterns::contains($query, $t)) !== []
            && array_filter($summary, static fn(array $p): bool => in_array(parse_url($p['url'], PHP_URL_PATH) ?? '/', ['', '/'], true)) !== []) {
            return [...$base, 'kind' => 'brand', 'harmful' => false, 'note' => 'A brand search with the home page among the results: several pages showing for your name is normal.'];
        }
        if ($switches >= 2) {
            return [...$base, 'kind' => 'switching', 'harmful' => true, 'note' => sprintf('The leading page changed %d times in %d weeks. Google does not settle on one page; make one page clearly the best answer and link to it from the other.', $switches, count($weeks))];
        }
        $leader = $counted[0];
        $second = $counted[1];
        $sharedWeeks = count(array_intersect_key($leader['weeks'], $second['weeks']));
        if ($switches === 0 && $sharedWeeks >= count($leader['weeks']) * self::STACKED_WEEKS && $summary[0]['position'] <= 10 && $summary[1]['position'] <= 10) {
            return [...$base, 'kind' => 'stacked', 'harmful' => false, 'note' => 'The same page leads every week and the other shows alongside it on page one: two listings for one search, usually good.'];
        }

        return [...$base, 'kind' => 'split', 'harmful' => null, 'note' => sprintf('%s leads, but another page takes %d %% of the impressions. Check whether the pages answer different intents before merging or redirecting.', $summary[0]['url'], (int) round($summary[1]['share'] * 100))];
    }
}

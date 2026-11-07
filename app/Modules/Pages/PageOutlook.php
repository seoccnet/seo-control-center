<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Opportunities\CtrModel;

/**
 * What a page could gain, from stored evidence only (0.47.0): the clicks
 * its searches would bring from the top 3, with the site's own click
 * curve, and how it compares with the pages that outrank it in the
 * latest competitor analysis that included it. Never calls providers.
 */
final class PageOutlook
{
    /** Position the click estimate aims for. */
    public const int TARGET = 3;
    /** Searches with fewer impressions in the range are too thin to estimate. */
    public const int MIN_IMPRESSIONS = 50;
    /** Below this share of the median of the pages above, a measure counts as behind. */
    public const float BEHIND = 0.6;
    /** Measures compared with the pages that outrank this one. */
    public const array MEASURES = [
        'words' => 'Words',
        'questions' => 'Questions answered',
        'tables' => 'Tables',
        'lists' => 'Lists',
        'images' => 'Images',
        'videos' => 'Videos',
        'markup' => 'Structured data types',
        'links' => 'Internal links',
    ];

    /**
     * Searches at positions 4–20 with enough impressions and the clicks
     * they would bring at position 3, most first (at most 5).
     *
     * @param list<PageQuery> $queries this page's searches in the range
     * @return list<array{query: string, position: float, impressions: int, clicks: int, expected: int, gain: int}>
     */
    public static function clickPotential(array $queries, CtrModel $model): array
    {
        $rows = [];
        foreach ($queries as $q) {
            $m = $q->metrics;
            if ($m->impressions < self::MIN_IMPRESSIONS || $m->position < 4 || $m->position > 20) {
                continue;
            }
            $expected = (int) round($m->impressions * $model->expected(self::TARGET));
            $gain = $expected - $m->clicks;
            if ($gain > 0) {
                $rows[] = ['query' => $q->query, 'position' => round($m->position, 1), 'impressions' => $m->impressions, 'clicks' => $m->clicks, 'expected' => $expected, 'gain' => $gain];
            }
        }
        usort($rows, static fn(array $a, array $b): int => [$b['gain'], $a['query']] <=> [$a['gain'], $b['query']]);

        return array_slice($rows, 0, 5);
    }

    /**
     * The page against the pages ranking above it in the newest finished
     * analysis whose own page is this URL; null without one.
     *
     * @param list<Analysis> $analyses finished analyses, any order
     * @return array{analysis: string, cluster: string, keyword: string, finished: ?string, own_position: ?int, above: list<array{position: int, url: string, domain: string}>, measures: list<array{key: string, label: string, own: int, median: float, behind: bool}>, own_modified: ?string, newest_above: ?string}|null
     */
    public static function compare(array $analyses, string $url): ?array
    {
        $match = null;
        foreach ($analyses as $a) {
            if ($a->ownUrl !== null && self::same($a->ownUrl, $url) && ($match === null || (string) $a->finishedAt > (string) $match->finishedAt)) {
                $match = $a;
            }
        }
        if ($match === null) {
            return null;
        }
        $own = null;
        foreach ($match->pages as $pageUrl => $page) {
            if (self::same((string) $pageUrl, $url) && $page->state === PageStructure::OK) {
                $own = $page;
            }
        }
        $above = [];
        $structures = [];
        foreach ($match->results as $r) {
            if ($r['own'] || ($match->ownPosition !== null && $r['position'] >= $match->ownPosition)) {
                continue;
            }
            $page = $match->pages[$r['url']] ?? null;
            if ($page !== null && $page->state === PageStructure::OK) {
                $above[] = ['position' => $r['position'], 'url' => $r['url'], 'domain' => $r['domain']];
                $structures[] = $page;
            }
            if (count($above) >= 5) {
                break;
            }
        }
        if ($own === null || $structures === []) {
            return null;
        }
        $measures = [];
        foreach (self::MEASURES as $key => $label) {
            $median = self::median(array_map(static fn(PageStructure $p): int => self::measure($p, $key), $structures));
            $value = self::measure($own, $key);
            $measures[] = ['key' => $key, 'label' => $label, 'own' => $value, 'median' => $median, 'behind' => $median >= 1 && $value < $median * self::BEHIND];
        }
        $dates = array_filter(array_map(static fn(PageStructure $p): ?string => $p->modified ?? $p->published, $structures));
        rsort($dates);

        return [
            'analysis' => $match->publicId,
            'cluster' => $match->clusterId,
            'keyword' => $match->keyword,
            'finished' => $match->finishedAt,
            'own_position' => $match->ownPosition,
            'above' => $above,
            'measures' => $measures,
            'own_modified' => self::day($own->modified ?? $own->published),
            'newest_above' => self::day($dates[0] ?? null),
        ];
    }

    private static function measure(PageStructure $p, string $key): int
    {
        return match ($key) {
            'words' => $p->wordCount,
            'questions' => count($p->questions),
            'tables' => $p->tables,
            'lists' => $p->lists,
            'images' => $p->images,
            'videos' => $p->videos,
            'markup' => count($p->schemaTypes),
            default => $p->internalLinks,
        };
    }

    /** @param non-empty-list<int> $values */
    private static function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    private static function day(?string $date): ?string
    {
        return $date !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1 ? substr($date, 0, 10) : null;
    }

    /** The same page: scheme, www, case of the host and a trailing slash do not matter. */
    private static function same(string $a, string $b): bool
    {
        $norm = static function (string $u): string {
            $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
            $path = rtrim((string) parse_url($u, PHP_URL_PATH), '/');
            $query = (string) parse_url($u, PHP_URL_QUERY);

            return $host . $path . ($query !== '' ? '?' . $query : '');
        };

        return $norm($a) === $norm($b);
    }
}

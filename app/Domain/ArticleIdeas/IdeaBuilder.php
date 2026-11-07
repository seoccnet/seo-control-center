<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * Turns competitors' ranked keywords into article ideas (ADR-041, G1).
 *
 * 1. Only competitor *articles* count (address classified as article or review)
 *    that rank on page one or two (position 20 or better) for a search.
 * 2. Each search belongs to the competitor article that ranks best for it. One
 *    idea is one article: articles are merged when they are language or country
 *    copies of one address (/blog-x and /us/blog-x) or when each ranks in the
 *    top 10 for the other's main search. An article that wins no search is
 *    listed with the one that wins its main search. Shared words alone never
 *    join articles: on a niche site most searches share them.
 * 3. Close variants Google gives one shared volume (same words, same volume)
 *    count once in the idea's demand.
 * 4. Your coverage decides the kind: no page of yours ranks within 30, or only a
 *    shop page does → New article; one of your articles does (below the top 3)
 *    → Update; any page in the top 3 already → no idea.
 * 5. Estimates, each with its basis: traffic from volume × your click-through at
 *    positions 2, 5 and 9; difficulty from keyword difficulty and the referring
 *    domains of page-one results; backlinks from those referring domains; months
 *    from stated assumptions by difficulty.
 */
final class IdeaBuilder
{
    public const int MAX = 50;
    public const int MIN_VOLUME = 10;
    /** Competitor rankings beyond page two say little about what wins a search. */
    public const int MAX_POSITION = 20;
    /** Two articles answer the same question when each ranks this high for the other's main search. */
    public const int MERGE_POSITION = 10;
    private const array ARTICLE_TYPES = [SerpFormat::ARTICLE, SerpFormat::REVIEW];
    /** Assumed months to reach page one, by difficulty, until the site's own history replaces them (G3). */
    private const array MONTHS = ['low' => [1, 3], 'medium' => [3, 6], 'hard' => [6, 12]];
    private const array MONTHS_UPDATE = ['low' => [1, 2], 'medium' => [2, 4], 'hard' => [4, 9]];
    private const array FACTOR = ['low' => 1.0, 'medium' => 0.6, 'hard' => 0.3];
    /** Words that don't change what a search is about, for spotting close variants. */
    private const array FILLER = ['a', 'an', 'and', 'are', 'between', 'difference', 'differences', 'do', 'does', 'for', 'how', 'in', 'is', 'of', 'on', 'or', 'the', 'to', 'versus', 'vs', 'what', 'with'];

    /**
     * @param list<array{domain: string, found: bool, url: string, keyword: string, volume: ?int, difficulty: ?int, position: ?int, traffic: ?float, avg_rd: ?int}> $rows competitors' ranked keywords
     * @param array<string, array{url: string, position: ?float, impressions: int}> $own your best page per normalized search
     * @param \Closure(string): bool $relevant false for searches the business profile or team ruled out
     * @param \Closure(int): float $ctr expected click-through (0–1) at a position
     * @param array<string, array{low: int, high: int, count: int}> $calibration the site's own months to the top 10 by "kind:level" (G3)
     * @return list<ArticleIdea> best first
     */
    public static function build(array $rows, array $own, \Closure $relevant, \Closure $ctr, ?int $ownReferringDomains, array $calibration = []): array
    {
        // Competitor articles and the searches they rank for on page one or two.
        $meta = [];
        $ranked = [];
        $owner = [];
        foreach ($rows as $row) {
            $keyword = Keyword::normalize($row['keyword']);
            $volume = (int) ($row['volume'] ?? 0);
            if ($keyword === '' || $volume < self::MIN_VOLUME || $row['position'] === null || $row['position'] > self::MAX_POSITION
                || !in_array(SerpFormat::classify($row['url']), self::ARTICLE_TYPES, true) || !$relevant($keyword)) {
                continue;
            }
            $meta[$row['url']] ??= ['domain' => $row['domain'], 'found' => $row['found'], 'url' => $row['url']];
            $ranked[$row['url']][$keyword] = $row;
            // Each search belongs to the article that ranks best for it.
            $current = $owner[$keyword] ?? null;
            if ($current === null || [$row['position'], -($row['traffic'] ?? 0.0), $row['url']] < [$current['position'], -($current['traffic'] ?? 0.0), $current['url']]) {
                $owner[$keyword] = $row;
            }
        }
        if ($meta === []) {
            return [];
        }
        $won = [];
        foreach ($owner as $keyword => $row) {
            $won[$row['url']][(string) $keyword] = ['keyword' => (string) $keyword, 'volume' => (int) $row['volume'], 'difficulty' => $row['difficulty']];
        }

        // One idea per article; articles answering the same main search are merged.
        $main = [];
        foreach (array_keys($meta) as $url) {
            $main[$url] = self::top($won[$url] ?? $ranked[$url] ?? []);
        }
        $parent = array_combine(array_keys($meta), array_keys($meta));
        $find = static function (string $url) use (&$parent): string {
            while ($parent[$url] !== $url) {
                $url = $parent[$url] = $parent[$parent[$url]];
            }

            return $url;
        };
        $union = static function (string $a, string $b) use (&$parent, $find): void {
            [$a, $b] = [$find($a), $find($b)];
            if ($a !== $b) {
                $parent[max($a, $b)] = min($a, $b);
            }
        };
        // Language or country copies of one article (/blog-x and /us/blog-x) are one article.
        $copies = [];
        foreach (array_keys($meta) as $url) {
            $key = self::articleKey($url);
            isset($copies[$key]) ? $union($url, $copies[$key]) : $copies[$key] = $url;
        }
        $leads = array_intersect_key($main, $won);
        foreach (array_keys($meta) as $url) {
            if (!isset($won[$url])) {
                // Outranked on every search: listed with the article that wins its main one.
                $union($url, $owner[$main[$url]]['url']);
                continue;
            }
            // Same question: each ranks in the top 10 for the other's main search. One article
            // reaching page two for another's search is common for strong sites and says little.
            foreach ($leads as $other => $keyword) {
                if ($other !== $url && ($ranked[$url][$keyword]['position'] ?? PHP_INT_MAX) <= self::MERGE_POSITION
                    && ($ranked[$other][$leads[$url]]['position'] ?? PHP_INT_MAX) <= self::MERGE_POSITION) {
                    $union($url, $other);
                }
            }
        }
        $groups = [];
        foreach (array_keys($meta) as $url) {
            $groups[$find($url)][] = $url;
        }

        $ideas = [];
        foreach ($groups as $group) {
            $keywords = [];
            $avgRd = [];
            $entries = [];
            foreach ($group as $url) {
                foreach ($won[$url] ?? [] as $keyword => $entry) {
                    $keywords[$keyword] = $entry;
                    $avgRd[$keyword] = max($avgRd[$keyword] ?? -1, $ranked[$url][$keyword]['avg_rd'] ?? -1);
                }
            }
            foreach ($group as $url) {
                $hits = array_intersect_key($ranked[$url] ?? [], $keywords);
                $entries[] = [
                    ...$meta[$url],
                    'position' => $hits === [] ? null : min(array_map(static fn(array $r): int => (int) $r['position'], $hits)),
                    'traffic' => round(array_sum(array_map(static fn(array $r): float => (float) ($r['traffic'] ?? 0.0), $hits)), 1),
                    'keywords' => count($hits),
                ];
            }
            $keywords = array_values($keywords);
            usort($keywords, static fn(array $a, array $b): int => [$b['volume'], $a['keyword']] <=> [$a['volume'], $b['keyword']]);
            $label = $keywords[0]['keyword'];
            $demand = self::distinct($keywords);

            $best = null;
            foreach ($keywords as $keyword) {
                $page = $own[$keyword['keyword']] ?? null;
                if ($page !== null && $page['position'] !== null && ($best === null || $page['position'] < ($best['position'] ?? INF))) {
                    $best = $page;
                }
            }
            if ($best !== null && $best['position'] !== null && $best['position'] <= 3.0) {
                continue;
            }
            $ranks = $best !== null && $best['position'] !== null && $best['position'] <= 30.0;
            $isArticle = $best !== null && in_array(SerpFormat::classify($best['url']), self::ARTICLE_TYPES, true);
            $kind = $ranks && $isArticle ? ArticleIdea::UPDATE : ArticleIdea::NEW;
            usort($entries, static fn(array $a, array $b): int => [$b['traffic'], $a['url']] <=> [$a['traffic'], $b['url']]);
            $competitors = array_slice($entries, 0, 5);
            $rd = max($avgRd[$label] ?? -1, -1);
            $prediction = self::predict($demand, $kind, $rd >= 0 ? $rd : null, $ownReferringDomains, $ctr, $calibration);
            $domains = count(array_unique(array_column($entries, 'domain')));
            $score = round($prediction['traffic']['expected'] * self::FACTOR[$prediction['difficulty']['level']] * ($kind === ArticleIdea::UPDATE ? 1.3 : 1.0) * ($domains >= 2 ? 1.2 : 1.0), 1);
            $count = count($entries);
            $reasons = [sprintf('%d competitor article%s from %d site%s rank%s for these searches.', $count, $count === 1 ? '' : 's', $domains, $domains === 1 ? '' : 's', $count === 1 ? 's' : '')];
            $reasons[] = match (true) {
                $kind === ArticleIdea::UPDATE && $best !== null => sprintf('Your page %s already ranks around position %s: improving it is usually faster than a new article.', (string) parse_url($best['url'], PHP_URL_PATH), number_format((float) $best['position'], 1)),
                $ranks && $best !== null => sprintf('Your page %s ranks around position %s, but it is a shop page, not an article: an article can answer the question and link to it.', (string) parse_url($best['url'], PHP_URL_PATH), number_format((float) $best['position'], 1)),
                $best !== null => 'One of your pages appears only beyond position 30 for them; no page of yours targets this topic.',
                default => 'No page of yours ranks for these searches.',
            };
            $variants = count($keywords) - count($demand);
            if ($variants > 0) {
                $reasons[] = sprintf('%d close variant%s share%s one search volume and %s counted once.', $variants, $variants === 1 ? '' : 's', $variants === 1 ? 's' : '', $variants === 1 ? 'is' : 'are');
            }
            $ideas[] = new ArticleIdea(hash('sha256', $label), $kind, $label, array_sum(array_column($demand, 'volume')), array_slice($keywords, 0, 15), $competitors, $best, $prediction, $score, $reasons);
        }
        usort($ideas, static fn(ArticleIdea $a, ArticleIdea $b): int => [$b->score, $a->mainKeyword] <=> [$a->score, $b->mainKeyword]);

        return array_slice($ideas, 0, self::MAX);
    }

    /**
     * The searches whose volume counts: Google gives close variants (the same
     * words reordered, pluralised, spelled apart or with filler words) one shared
     * volume, so a search with the volume of a counted one and a subset or
     * superset of its words (or the same letters) is left out.
     *
     * @param list<array{keyword: string, volume: int, difficulty: ?int}> $keywords
     * @return list<array{keyword: string, volume: int, difficulty: ?int}>
     */
    public static function distinct(array $keywords): array
    {
        // Shortest search first within a volume, so the core wording is the one counted.
        $order = $keywords;
        usort($order, static fn(array $a, array $b): int => [$b['volume'], count(self::words($a['keyword'])), $a['keyword']] <=> [$a['volume'], count(self::words($b['keyword'])), $b['keyword']]);
        $counted = [];
        foreach ($order as $keyword) {
            $words = self::words($keyword['keyword']);
            foreach ($counted as [$other, $otherWords]) {
                if ($other['volume'] === $keyword['volume'] && (array_diff($words, $otherWords) === [] || array_diff($otherWords, $words) === [] || implode('', $words) === implode('', $otherWords))) {
                    continue 2;
                }
            }
            $counted[] = [$keyword, $words];
        }
        $kept = array_flip(array_map(static fn(array $c): string => $c[0]['keyword'], $counted));

        return array_values(array_filter($keywords, static fn(array $k): bool => isset($kept[$k['keyword']])));
    }

    /**
     * The highest-volume search of an article.
     *
     * @param array<array-key, array{volume: ?int, ...}> $keywords
     */
    private static function top(array $keywords): string
    {
        $best = '';
        $volume = -1;
        foreach ($keywords as $keyword => $row) {
            $keyword = (string) $keyword;
            if ((int) $row['volume'] > $volume || ((int) $row['volume'] === $volume && strcmp($keyword, $best) < 0)) {
                [$best, $volume] = [$keyword, (int) $row['volume']];
            }
        }

        return $best;
    }

    /**
     * An article's address without a leading language or country folder
     * (/us/, /en/, /en-gb/, /de_at/), so its copies share one key.
     */
    public static function articleKey(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return $url;
        }
        $host = (string) preg_replace('/^www\./', '', strtolower((string) ($parts['host'] ?? '')));
        $path = (string) preg_replace('#^/[a-z]{2}(?:[-_][a-z]{2})?(?=/)#i', '', (string) ($parts['path'] ?? '/'));

        return $host . rtrim($path, '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * The content words of a search or heading: lower case, filler words
     * dropped, simple plurals singular.
     *
     * @return list<string>
     */
    public static function words(string $keyword): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($keyword), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            if (!in_array($word, self::FILLER, true)) {
                $words[] = mb_strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') ? mb_substr($word, 0, -1) : $word;
            }
        }

        return array_values(array_unique($words));
    }

    /**
     * @param list<array{keyword: string, volume: int, difficulty: ?int}> $keywords
     * @param \Closure(int): float $ctr
     * @param array<string, array{low: int, high: int, count: int}> $calibration
     * @return array{traffic: array{low: int, expected: int, high: int}, difficulty: array{level: string, reasons: list<string>}, backlinks: array{low: int, high: int, basis: string}|null, months: array{low: int, high: int, basis: string}}
     */
    public static function predict(array $keywords, string $kind, ?int $avgRd, ?int $ownReferringDomains, \Closure $ctr, array $calibration = []): array
    {
        $traffic = [];
        foreach (['low' => 9, 'expected' => 5, 'high' => 2] as $name => $position) {
            $traffic[$name] = (int) round(array_sum(array_map(static fn(array $k): float => $k['volume'] * $ctr($position), $keywords)));
        }
        $difficulty = $keywords[0]['difficulty'] ?? null;
        $points = 0;
        $reasons = [];
        if ($difficulty !== null) {
            $points += $difficulty >= 60 ? 2 : ($difficulty >= 30 ? 1 : 0);
            $reasons[] = sprintf('Keyword difficulty %d/100%s.', $difficulty, $difficulty >= 60 ? ' (hard)' : ($difficulty >= 30 ? ' (medium)' : ' (low)'));
        } else {
            $reasons[] = 'No keyword difficulty known for the main search.';
        }
        if ($avgRd !== null) {
            $points += $avgRd >= 50 ? 2 : ($avgRd >= 10 ? 1 : 0);
            $reasons[] = sprintf('Pages on page one have about %d referring domains each.', $avgRd);
            if ($ownReferringDomains !== null && $ownReferringDomains < 20 && $avgRd >= 10) {
                ++$points;
                $reasons[] = sprintf('Your whole domain has %d referring domains, so new articles get little link strength from it.', $ownReferringDomains);
            }
        }
        $level = $points >= 4 ? 'hard' : ($points >= 2 ? 'medium' : 'low');
        $backlinks = $avgRd === null ? null : [
            'low' => (int) ceil($avgRd * 0.3),
            'high' => $avgRd,
            'basis' => $avgRd === 0
                ? 'Pages on page one have no referring domains: content and internal links can be enough.'
                : sprintf('Pages on page one average %d referring domains each. A new article starts with none; about %d–%d would put it in line with them. Internal links and your domain carry part of it.', $avgRd, (int) ceil($avgRd * 0.3), $avgRd),
        ];
        [$low, $high] = ($kind === ArticleIdea::UPDATE ? self::MONTHS_UPDATE : self::MONTHS)[$level];
        $what = $kind === ArticleIdea::UPDATE ? 'improved pages' : 'new articles';
        $basis = 'An assumption by difficulty' . ($kind === ArticleIdea::UPDATE ? ' for improving an existing page' : ' for a new article') . ', until SEOcc has enough of your own ranking history to replace it.';
        // G3: the site's own results replace the assumption once enough tracked articles reached the top 10.
        $own = $calibration[$kind . ':' . $level] ?? null;
        if ($own !== null) {
            [$low, $high] = [$own['low'], $own['high']];
            $basis = sprintf('Your site: %d tracked %s of %s difficulty reached the top 10 in about %d–%d months (middle half). Only articles that got there count.', $own['count'], $what, $level, $low, $high);
        }

        return [
            'traffic' => $traffic,
            'difficulty' => ['level' => $level, 'reasons' => $reasons],
            'backlinks' => $backlinks,
            'months' => ['low' => $low, 'high' => $high, 'basis' => $basis],
        ];
    }
}

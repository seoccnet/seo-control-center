<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ContentInventory;

use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * The shared content inventory (ADR-042): every page, draft and planned
 * article SEOcc knows about, one row per matching key, with each source's
 * own status and observation date kept apart. Nothing here is inferred to
 * be public: a CMS draft is not a page, a canonical is not a redirect, and
 * "no ranking page found" never means "no page exists".
 */
final class Inventory
{
    public const string CMS = 'cms';
    public const string CRAWL = 'crawl';
    public const string SITEMAP = 'sitemap';
    public const string SEARCH = 'search_console';
    public const string BRIEF = 'brief';
    public const string PUBLISHED = 'published';
    public const string PLAN = 'topic_plan';
    public const string OWNER = 'topic_owner';

    public const array SOURCES = [
        self::CMS => 'CMS (CSV or manual)',
        self::CRAWL => 'Latest crawl',
        self::SITEMAP => 'Sitemap',
        self::SEARCH => 'Search Console',
        self::BRIEF => 'Content brief',
        self::PUBLISHED => 'Published from an idea',
        self::PLAN => 'Topic plan',
        self::OWNER => 'Topic owner',
    ];

    /** Headline states, from the strongest evidence of what a visitor gets. */
    public const array STATES = [
        'live' => 'Live',
        'noindex' => 'Live, noindex',
        'canonicalised' => 'Canonical elsewhere',
        'redirect' => 'Redirects',
        'retired' => 'Retired or gone',
        'error' => 'Crawl error',
        'seen' => 'Seen by Google, not crawled',
        'sitemap' => 'In sitemap only',
        'published' => 'Published in CMS, not seen yet',
        'scheduled' => 'Scheduled in CMS',
        'draft' => 'Draft in CMS',
        'planned' => 'Planned',
    ];

    private const array STOPWORDS = ['the', 'and', 'for', 'with', 'how', 'what', 'why', 'when', 'your', 'you', 'are', 'can', 'does', 'from', 'that', 'this', 'into', 'best', 'guide', 'vs'];

    /**
     * Joins source records into rows.
     *
     * @param list<array{source: string, url: ?string, ref: string, title: ?string, status: string, observed_at: ?string, detail: string, keyword?: ?string, target?: ?string}> $records
     *        A record without an address is kept as its own row (a draft or plan); `target` is a redirect or canonical address.
     * @return list<array{key: string, url: ?string, title: ?string, state: string, sources: list<array<string, mixed>>, variants: list<string>, candidates: list<array{url: string, title: ?string}>}>
     */
    public static function build(array $records): array
    {
        $rows = [];
        foreach ($records as $record) {
            $key = $record['url'] !== null ? UrlKey::of($record['url']) : null;
            $key ??= 'record:' . $record['source'] . ':' . $record['ref'];
            $row = $rows[$key] ?? ['key' => $key, 'url' => null, 'title' => null, 'state' => 'planned', 'sources' => [], 'variants' => [], 'candidates' => []];
            if ($record['url'] !== null) {
                $row['url'] ??= $record['url'];
                if (!in_array($record['url'], $row['variants'], true) && $record['url'] !== $row['url']) {
                    $row['variants'][] = $record['url'];
                }
            }
            $row['title'] ??= $record['title'] !== null && trim($record['title']) !== '' ? trim($record['title']) : null;
            $row['sources'][] = $record;
            $rows[$key] = $row;
        }
        foreach ($rows as $key => $row) {
            $rows[$key]['state'] = self::state($row['sources'], $row['key']);
        }
        // Rows without an address may be a page that exists already: offer likely matches for a person to confirm.
        $addressed = array_filter($rows, static fn(array $r): bool => $r['url'] !== null);
        foreach ($rows as $key => $row) {
            if ($row['url'] === null && $row['title'] !== null) {
                $rows[$key]['candidates'] = self::candidates($row['title'], $addressed);
            }
        }

        return array_values($rows);
    }

    /** Sources that describe a page's own topic; a topic plan or topic owner only points at the page (0.33.1). */
    private const array OWN_TOPIC = [self::CMS, self::BRIEF, self::PUBLISHED];

    /**
     * Rows on a topic: every meaningful word of the topic is in a row's title,
     * address words or the keyword of a source describing the page itself.
     * `article` says whether the row covers it as written content: an article
     * or review page (as article ideas judge your own pages), a CMS record, a
     * brief or a published article. Shop and other pages on the topic are
     * returned with article false: they exist, but do not answer the search.
     *
     * @param list<array{key: string, url: ?string, title: ?string, state: string, sources: list<array<string, mixed>>}> $rows
     * @return list<array{key: string, url: ?string, title: ?string, state: string, sources: list<array<string, mixed>>, article: bool}>
     */
    public static function covering(array $rows, string $topic): array
    {
        $want = self::words($topic);
        if ($want === []) {
            return [];
        }
        $found = [];
        foreach ($rows as $row) {
            if ($row['state'] === 'redirect' || $row['state'] === 'retired') {
                continue;
            }
            $own = array_values(array_filter($row['sources'], static fn(array $s): bool => in_array($s['source'], self::OWN_TOPIC, true)));
            $have = self::words(implode(' ', [$row['title'] ?? '', $row['url'] !== null ? UrlKey::slugWords($row['url']) : '', ...array_map(static fn(array $s): string => (string) ($s['keyword'] ?? '') . ' ' . (string) ($s['title'] ?? ''), $own)]));
            if (array_diff($want, $have) === []) {
                $article = $own !== [] || ($row['url'] !== null && in_array(SerpFormat::classify($row['url'], $row['title']), [SerpFormat::ARTICLE, SerpFormat::REVIEW], true));
                $found[] = [...$row, 'article' => $article];
            }
        }
        // Written content first, then other pages on the topic.
        usort($found, static fn(array $a, array $b): int => (int) $b['article'] <=> (int) $a['article']);

        return $found;
    }

    /** @return list<string> lower-case words of 3+ letters, plural s removed, stopwords out */
    public static function words(string $text): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $word) {
            if (mb_strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }
            $words[] = mb_strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') ? mb_substr($word, 0, -1) : $word;
        }

        return array_values(array_unique($words));
    }

    /** @param list<array<string, mixed>> $sources */
    private static function state(array $sources, string $key): string
    {
        $by = [];
        foreach ($sources as $source) {
            $by[$source['source']][] = $source;
        }
        $crawl = $by[self::CRAWL][0] ?? null;
        if ($crawl !== null) {
            $status = $crawl['status'];
            if ($status === 'redirect') {
                return 'redirect';
            }
            if ($status === 'gone') {
                return 'retired';
            }
            if ($status === 'error') {
                return 'error';
            }
            if ($status === 'ok') {
                $target = isset($crawl['target']) && is_string($crawl['target']) ? UrlKey::of($crawl['target']) : null;
                if ($target !== null && $target !== $key) {
                    return 'canonicalised';
                }

                return ($crawl['noindex'] ?? false) === true ? 'noindex' : 'live';
            }
        }
        $cms = array_column($by[self::CMS] ?? [], 'status');
        if (in_array(ContentItem::RETIRED, $cms, true)) {
            return 'retired';
        }
        if (isset($by[self::SEARCH])) {
            return 'seen';
        }
        if (isset($by[self::SITEMAP])) {
            return 'sitemap';
        }
        if (in_array(ContentItem::PUBLISHED, $cms, true) || isset($by[self::PUBLISHED])) {
            return 'published';
        }
        if (in_array(ContentItem::SCHEDULED, $cms, true)) {
            return 'scheduled';
        }
        if (in_array(ContentItem::DRAFT, $cms, true)) {
            return 'draft';
        }

        return 'planned';
    }

    /**
     * @param array<string, array{url: ?string, title: ?string}> $rows
     * @return list<array{url: string, title: ?string}>
     */
    private static function candidates(string $title, array $rows): array
    {
        $want = self::words($title);
        if (count($want) < 2) {
            return [];
        }
        $scored = [];
        foreach ($rows as $row) {
            $url = (string) $row['url'];
            $have = self::words(($row['title'] ?? '') . ' ' . UrlKey::slugWords($url));
            $shared = count(array_intersect($want, $have));
            $score = $shared / max(1, count(array_unique([...$want, ...$have])));
            if ($shared >= 2 && $score >= 0.5) {
                $scored[] = ['url' => $url, 'title' => $row['title'], 'score' => $score];
            }
        }
        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['url'], $b['url']));

        return array_map(static fn(array $c): array => ['url' => $c['url'], 'title' => $c['title']], array_slice($scored, 0, 3));
    }
}

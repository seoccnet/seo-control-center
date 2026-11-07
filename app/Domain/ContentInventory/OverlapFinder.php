<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ContentInventory;

use SeoControlCenter\Domain\Clusters\PageKey;
use SeoControlCenter\Domain\InternalLinks\LinkIntent;
use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * Overlap review candidates (ADR-042, 0.36.0): pages with near-identical
 * text, several pages shown for one search, article ideas that are the same
 * subject in other words, and topic archives that split one subject. Each
 * candidate comes with its evidence and what is uncertain about it. Nothing
 * is merged: several pages ranking for a search is not harmful by itself,
 * and pages that share words can still serve different purposes.
 */
final class OverlapFinder
{
    public const string DUPLICATE = 'near_duplicate';
    public const string SHARED_QUERY = 'shared_query';
    public const string SYNONYM_IDEAS = 'synonym_ideas';
    public const string ARCHIVES = 'split_archives';

    public const array KINDS = [
        self::DUPLICATE => 'Near-duplicate text',
        self::SHARED_QUERY => 'Several pages for one search',
        self::SYNONYM_IDEAS => 'Article ideas on the same subject',
        self::ARCHIVES => 'Topic archives splitting a subject',
    ];

    /** Words per shingle, the share of shared shingles that makes two pages near-duplicates, and the page count above which a shingle is template text. */
    private const int SHINGLE = 5;
    private const float DUPLICATE_SIMILARITY = 0.5;
    private const int TEMPLATE_PAGES = 10;
    private const int MIN_SHINGLES = 20;
    private const array ARCHIVE_FOLDERS = ['tag', 'tags', 'topic', 'topics', 'category', 'categories', 'archive', 'archives'];
    /** Listing pages that are not compared as text (0.36.2: author archives too). */
    private const array LISTING_FOLDERS = [...self::ARCHIVE_FOLDERS, 'author', 'authors'];
    /** A word in at least this share of the site's archive addresses (and at least 3) is common there and does not make two archives one subject. */
    private const float COMMON_SHARE = 0.1;
    /** Words that only join an archive address's subject words (0.36.3: short words such as "ph" and "f1" and question words such as "when" are kept). */
    private const array CONNECTORS = ['a', 'an', 'and', 'are', 'as', 'at', 'by', 'for', 'from', 'in', 'into', 'is', 'it', 'my', 'of', 'on', 'or', 'the', 'to', 'vs', 'with', 'you', 'your'];
    /** Candidates kept per kind, strongest first (0.36.1: one kind can no longer crowd out the others). */
    public const int PER_KIND = 100;

    /**
     * @param list<array{url: string, title: ?string, text: string}> $pages crawled pages with their body paragraphs joined
     * @param list<array{query: string, kind: string, harmful: ?bool, note: string, pages: list<array{url: string, share: float, position: float}>}> $queryOverlaps from topics (Clusters)
     * @param list<array{key: string, main_keyword: string, kind: string}> $ideas article ideas
     * @return array{candidates: list<array{key: string, kind: string, members: list<string>, evidence: list<string>, uncertainty: string, distinct_purpose: ?string, score: float}>, totals: array<string, int>}
     *         candidates: at most PER_KIND per kind, strongest first; totals: every candidate found per kind, before that cap
     */
    public static function find(array $pages, array $queryOverlaps, array $ideas): array
    {
        $candidates = [];
        $totals = [];
        $titles = array_column($pages, 'title', 'url');
        foreach ([self::DUPLICATE => self::duplicates($pages), self::SHARED_QUERY => self::sharedQueries($queryOverlaps), self::SYNONYM_IDEAS => self::synonyms($ideas), self::ARCHIVES => self::archives($pages)] as $kind => $found) {
            $totals[$kind] = count($found);
            usort($found, static fn(array $a, array $b): int => [$b['score'], $a['key']] <=> [$a['score'], $b['key']]);
            foreach (array_slice($found, 0, self::PER_KIND) as $candidate) {
                if (in_array($kind, [self::DUPLICATE, self::SHARED_QUERY], true)) {
                    $candidate['distinct_purpose'] = self::purpose($candidate['members'], $titles);
                }
                $candidates[] = $candidate;
            }
        }
        usort($candidates, static fn(array $a, array $b): int => [$b['score'], $a['key']] <=> [$a['score'], $b['key']]);

        return ['candidates' => $candidates, 'totals' => $totals];
    }

    /**
     * Only the near-duplicate pages, strongest first and at most PER_KIND, with
     * the same evidence and purpose note as the overlap review (0.44.0: the
     * merge opportunity).
     *
     * @param list<array{url: string, title: ?string, text: string}> $pages
     * @return list<array{key: string, kind: string, members: list<string>, evidence: list<string>, uncertainty: string, distinct_purpose: ?string, score: float}>
     */
    public static function nearDuplicates(array $pages): array
    {
        $found = self::duplicates($pages);
        usort($found, static fn(array $a, array $b): int => [$b['score'], $a['key']] <=> [$a['score'], $b['key']]);
        $titles = array_column($pages, 'title', 'url');

        return array_map(static fn(array $c): array => [...$c, 'distinct_purpose' => self::purpose($c['members'], $titles)], array_slice($found, 0, self::PER_KIND));
    }

    /** Listing pages are compared by the archive check, not as text: a paginated copy (?page=2) or a tag, topic or category archive. */
    private static function listing(string $url): bool
    {
        if (PageKey::of($url) !== $url) {
            return true;
        }
        $parts = explode('/', trim((string) parse_url($url, PHP_URL_PATH), '/'));

        return count($parts) >= 2 && in_array(strtolower($parts[count($parts) - 2]), self::LISTING_FOLDERS, true);
    }

    /**
     * The stable key of a candidate: its kind and its members, order ignored.
     *
     * @param list<string> $members
     */
    public static function key(string $kind, array $members): string
    {
        sort($members);

        return hash('sha256', $kind . "\n" . implode("\n", $members));
    }

    /**
     * @param list<array{url: string, title: ?string, text: string}> $pages
     * @return list<array{key: string, kind: string, members: list<string>, evidence: list<string>, uncertainty: string, distinct_purpose: ?string, score: float}>
     */
    private static function duplicates(array $pages): array
    {
        $sets = [];
        $frequency = [];
        foreach ($pages as $i => $page) {
            if (self::listing($page['url'])) {
                continue;
            }
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($page['text']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $set = [];
            for ($w = 0; $w + self::SHINGLE <= count($words); $w++) {
                $set[crc32(implode(' ', array_slice($words, $w, self::SHINGLE)))] = true;
            }
            $sets[$i] = $set;
            foreach ($set as $shingle => $_) {
                $frequency[$shingle] = ($frequency[$shingle] ?? 0) + 1;
            }
        }
        // Text repeated on many pages is template text (delivery notes, footers in the body): it does not make pages duplicates.
        $index = [];
        foreach ($sets as $i => $set) {
            foreach ($set as $shingle => $_) {
                if ($frequency[$shingle] > self::TEMPLATE_PAGES) {
                    unset($sets[$i][$shingle]);
                } else {
                    $index[$shingle][] = $i;
                }
            }
        }
        $shared = [];
        foreach ($index as $members) {
            for ($a = 0; $a < count($members); $a++) {
                for ($b = $a + 1; $b < count($members); $b++) {
                    $shared[$members[$a] . ':' . $members[$b]] = ($shared[$members[$a] . ':' . $members[$b]] ?? 0) + 1;
                }
            }
        }
        $out = [];
        foreach ($shared as $pair => $count) {
            [$a, $b] = array_map('intval', explode(':', $pair));
            $sizeA = count($sets[$a]);
            $sizeB = count($sets[$b]);
            if ($sizeA < self::MIN_SHINGLES || $sizeB < self::MIN_SHINGLES) {
                continue;
            }
            $similarity = $count / ($sizeA + $sizeB - $count);
            if ($similarity < self::DUPLICATE_SIMILARITY) {
                continue;
            }
            $members = [$pages[$a]['url'], $pages[$b]['url']];
            $out[] = [
                'key' => self::key(self::DUPLICATE, $members),
                'kind' => self::DUPLICATE,
                'members' => $members,
                'evidence' => [sprintf('%d %% of their body text is the same (word sequences outside repeated template text).', (int) round($similarity * 100))],
                'uncertainty' => 'Read from up to 25 paragraphs per page in the latest crawl; text loaded by JavaScript and images is not compared.',
                'distinct_purpose' => null,
                'score' => round(50 + $similarity * 50, 2),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{query: string, kind: string, harmful: ?bool, note: string, pages: list<array{url: string, share: float, position: float}>}> $overlaps
     * @return list<array{key: string, kind: string, members: list<string>, evidence: list<string>, uncertainty: string, distinct_purpose: ?string, score: float}>
     */
    private static function sharedQueries(array $overlaps): array
    {
        $groups = [];
        foreach ($overlaps as $overlap) {
            $members = array_values(array_unique(array_column($overlap['pages'], 'url')));
            if (count($members) < 2 || $overlap['kind'] === 'brand') {
                continue;
            }
            sort($members);
            $key = self::key(self::SHARED_QUERY, $members);
            $groups[$key] ??= ['key' => $key, 'kind' => self::SHARED_QUERY, 'members' => $members, 'evidence' => [], 'uncertainty' => 'Several pages showing for one search is common and often fine (a category and a guide, for example). Only a leading page that keeps switching suggests the pages compete.', 'distinct_purpose' => null, 'score' => 0.0, 'harmful' => 0];
            $shares = implode(', ', array_map(static fn(array $p): string => sprintf('%s %d %% at %.1f', (string) parse_url($p['url'], PHP_URL_PATH), (int) round($p['share'] * 100), $p['position']), $overlap['pages']));
            $groups[$key]['evidence'][] = sprintf('"%s": %s. %s', $overlap['query'], $shares, $overlap['note']);
            $groups[$key]['score'] += $overlap['harmful'] === true ? 20.0 : ($overlap['harmful'] === null ? 5.0 : 1.0);
            $groups[$key]['harmful'] += $overlap['harmful'] === true ? 1 : 0;
        }

        return array_map(static function (array $g): array {
            $g['evidence'] = array_slice($g['evidence'], 0, 10);
            if ($g['harmful'] === 0) {
                $g['uncertainty'] = 'No search here shows the leading page switching back and forth. ' . $g['uncertainty'];
            }
            unset($g['harmful']);

            return $g;
        }, array_values($groups));
    }

    /**
     * @param list<array{key: string, main_keyword: string, kind: string}> $ideas
     * @return list<array{key: string, kind: string, members: list<string>, evidence: list<string>, uncertainty: string, distinct_purpose: ?string, score: float}>
     */
    private static function synonyms(array $ideas): array
    {
        $out = [];
        $words = array_map(static fn(array $i): array => Inventory::words($i['main_keyword']), $ideas);
        for ($a = 0; $a < count($ideas); $a++) {
            for ($b = $a + 1; $b < count($ideas); $b++) {
                if ($words[$a] === [] || $words[$b] === []) {
                    continue;
                }
                $shared = count(array_intersect($words[$a], $words[$b]));
                $union = count(array_unique([...$words[$a], ...$words[$b]]));
                $similarity = $shared / $union;
                if ($similarity < 0.75 && !($shared >= 2 && (array_diff($words[$a], $words[$b]) === [] || array_diff($words[$b], $words[$a]) === []))) {
                    continue;
                }
                $members = [$ideas[$a]['main_keyword'], $ideas[$b]['main_keyword']];
                $out[] = [
                    'key' => self::key(self::SYNONYM_IDEAS, [$ideas[$a]['key'], $ideas[$b]['key']]),
                    'kind' => self::SYNONYM_IDEAS,
                    'members' => $members,
                    'evidence' => [sprintf('"%s" and "%s" share %d of %d meaningful words.', $members[0], $members[1], $shared, $union)],
                    'uncertainty' => 'Similar words can still be different intents (a cultivar review against a grow guide, for example). One article can often cover both; decide before writing two.',
                    'distinct_purpose' => null,
                    'score' => round(30 + $similarity * 30, 2),
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array{url: string, title: ?string, text: string}> $pages
     * @return list<array{key: string, kind: string, members: list<string>, evidence: list<string>, uncertainty: string, distinct_purpose: ?string, score: float}>
     */
    private static function archives(array $pages): array
    {
        $archives = [];
        foreach ($pages as $page) {
            $path = trim((string) parse_url($page['url'], PHP_URL_PATH), '/');
            $parts = explode('/', $path);
            if (count($parts) >= 2 && in_array(strtolower($parts[count($parts) - 2]), self::ARCHIVE_FOLDERS, true) && parse_url($page['url'], PHP_URL_QUERY) === null) {
                $slug = UrlKey::slugWords($page['url']);
                $raw = self::archiveWords($slug);
                $archives[] = ['url' => $page['url'], 'slug' => $slug, 'raw' => $raw, 'words' => array_keys($raw)];
            }
        }
        // Words in many archive addresses ("outdoor", "tomato") are the site's vocabulary, not a subject.
        $frequency = [];
        foreach ($archives as $archive) {
            foreach ($archive['words'] as $word) {
                $frequency[$word] = ($frequency[$word] ?? 0) + 1;
            }
        }
        $common = max(3, (int) ceil(count($archives) * self::COMMON_SHARE));
        $distinct = static fn(array $words): array => array_values(array_filter($words, static fn(string $w): bool => ($frequency[$w] ?? 0) < $common));
        $out = [];
        for ($a = 0; $a < count($archives); $a++) {
            for ($b = $a + 1; $b < count($archives); $b++) {
                [$x, $y] = count($archives[$a]['words']) <= count($archives[$b]['words']) ? [$archives[$a], $archives[$b]] : [$archives[$b], $archives[$a]];
                if ($x['words'] === []) {
                    continue;
                }
                $sharedDistinct = array_intersect($distinct($x['words']), $distinct($y['words']));
                if ($sharedDistinct === []) {
                    continue;
                }
                // The narrower archive's subject lies inside the wider one, or the two share most of their distinctive words.
                $inside = array_diff($x['words'], $y['words']) === [];
                $union = array_unique([...$distinct($x['words']), ...$distinct($y['words'])]);
                if (!$inside && count($sharedDistinct) / max(1, count($union)) < 0.67) {
                    continue;
                }
                // Distinctive words only one of them has make it a subtopic, which ranks below archives on one subject.
                $extra = [];
                foreach ([[$x, $y], [$y, $x]] as [$one, $other]) {
                    foreach (array_diff($distinct($one['words']), $other['words']) as $word) {
                        $extra[] = '"' . $one['raw'][$word] . '"';
                    }
                }
                $members = [$archives[$a]['url'], $archives[$b]['url']];
                $out[] = [
                    'key' => self::key(self::ARCHIVES, $members),
                    'kind' => self::ARCHIVES,
                    'members' => $members,
                    'evidence' => [match (true) {
                        $inside && count($x['words']) === count($y['words']) => sprintf('"%s" and "%s" have the same subject words.', $x['slug'], $y['slug']),
                        $inside => sprintf('The narrower archive "%s" is a part of "%s".', $x['slug'], $y['slug']),
                        default => sprintf('"%s" and "%s" share most of their distinctive words.', $x['slug'], $y['slug']),
                    }],
                    'uncertainty' => ($extra === []
                        ? 'Archives list posts; two archives on one subject split the posts and the links between them. A narrower archive can still be useful when it holds enough posts of its own.'
                        : sprintf('They differ in %s, so one may be a subtopic of the other; a parent and a child archive can both be useful.', implode(', ', $extra)))
                        . ' Words used in many of your archive addresses are not counted.',
                    'distinct_purpose' => null,
                    'score' => ($extra === [] ? 30.0 : 20.0) + count($sharedDistinct),
                ];
            }
        }

        return $out;
    }

    /**
     * An archive address's subject words, folded (plural "s" dropped) and keyed to how the address writes them.
     *
     * @return array<string, string>
     */
    private static function archiveWords(string $slug): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($slug)) ?: [] as $word) {
            if ($word === '' || in_array($word, self::CONNECTORS, true)) {
                continue;
            }
            $folded = mb_strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') ? mb_substr($word, 0, -1) : $word;
            $words[$folded] ??= $word;
        }

        return $words;
    }

    /**
     * Whether the pages serve different purposes despite sharing words: a shop page against an article, or
     * a prize draw against a rewards page. Then the overlap is likely fine.
     *
     * @param list<string> $members
     * @param array<string, ?string> $titles
     */
    private static function purpose(array $members, array $titles): ?string
    {
        $formats = array_values(array_unique(array_map(static fn(string $u): string => SerpFormat::classify($u, $titles[$u] ?? null), $members)));
        $known = array_values(array_filter($formats, static fn(string $f): bool => $f !== 'other'));
        if (count($known) >= 2) {
            return 'The pages are of different kinds (' . implode(', ', $known) . '): they may answer different intents and need not be merged.';
        }
        if (count($members) === 2) {
            $text = static fn(string $u): string => ($titles[$u] ?? '') . ' ' . str_replace(['-', '_', '/'], ' ', (string) parse_url($u, PHP_URL_PATH));
            $conflict = LinkIntent::conflict($text($members[0]), $text($members[1]));
            if ($conflict !== null) {
                return 'The pages serve different purposes (' . $conflict . '): they may need to stay separate.';
            }
        }

        return null;
    }
}

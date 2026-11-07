<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\InternalLinks;

use SeoControlCenter\Domain\Opportunities\QueryPatterns;

/**
 * Rule-based internal link suggestions (ADR-040, F1). A crawled paragraph
 * that mentions a target page's topic (one of its searches, as whole words
 * in order, plurals folded) but whose page does not link to that target yet
 * becomes a suggestion: the source page, the paragraph and the matched words
 * as anchor. Stronger targets (more demand) and stronger sources (more
 * internal links pointing at them) come first.
 *
 * Since 0.25.3: the words of a match are separated by spaces or hyphens
 * only (never across punctuation); pages that compete for the topic are no
 * source; and a search that has its own page (an address ending in the
 * search, e.g. /triploid-seeds for "triploid seeds") is not matched for the
 * owner of a broader topic, because a link on those words belongs there.
 *
 * Since 0.25.4: that also holds for longer searches containing every word of
 * such a page ("regular tomato seeds" and /regular-seeds), and only
 * sentence-like paragraphs are used (at least MIN_WORDS words, no SKU-style
 * product codes).
 *
 * Since 0.35.0 (ADR-042): a match whose paragraph is about another purpose
 * than the target page (rewards against a prize draw, an affiliate programme
 * against either) is left out and counted, never suggested.
 */
final class LinkSuggester
{
    public const int PER_TARGET = 5;
    public const int PER_SOURCE = 3;
    public const int MAX = 300;
    public const int ANCHOR_LENGTH = 160;
    public const int MIN_WORDS = 8;

    /**
     * @param list<array{id: int, topic: string, cluster_id: ?string, phrases: list<string>, demand: float, exclude?: list<int>}> $targets owner pages and their searches,
     *        most important phrase first; exclude: pages that compete for the topic and never get a suggestion for it
     * @param list<array{page_id: int, text: string}> $paragraphs body paragraphs of crawled pages
     * @param array<int, array{url: string, inbound: int, title?: ?string}> $pages crawled, indexable HTML pages that may give or receive links
     * @param array<string, true> $linked existing body-text links as "from:to" page IDs (a menu link does not count)
     * @param array<string, int> $dedicated stemKey() of a page's last address segment => that page
     * @param int $leftOut set to the number of matches left out because the paragraph is about another purpose (LinkIntent)
     * @return list<array{source_page_id: int, target_page_id: int, cluster_id: ?string, topic: string, anchor: string, paragraph: string, score: float}>
     */
    public static function suggest(array $targets, array $paragraphs, array $pages, array $linked, array $dedicated = [], int &$leftOut = 0): array
    {
        $leftOut = 0;
        // Every paragraph once: its words with byte offsets and folded stems, plus an index from stem to positions.
        $words = [];
        $index = [];
        foreach ($paragraphs as $p => $paragraph) {
            if (!isset($pages[$paragraph['page_id']]) || !self::prose($paragraph['text'])) {
                continue;
            }
            preg_match_all('/[\p{L}\p{N}]+/u', $paragraph['text'], $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $w => [$word, $offset]) {
                $stem = QueryPatterns::stem(mb_strtolower($word));
                $words[$p][$w] = [$stem, $offset, $offset + strlen($word)];
                $index[$stem][] = [$p, $w];
            }
        }

        // Dedicated pages as word sets, to catch searches that contain all of a page's words.
        $owners = [];
        foreach ($dedicated as $key => $page) {
            $owners[] = [array_flip(explode(' ', $key)), $page];
        }
        $candidates = [];
        foreach ($targets as $target) {
            if (!isset($pages[$target['id']])) {
                continue;
            }
            $best = [];
            $exclude = array_flip($target['exclude'] ?? []);
            $purpose = implode(' ', [$target['topic'], $pages[$target['id']]['title'] ?? '', str_replace(['-', '_', '/'], ' ', (string) parse_url($pages[$target['id']]['url'], PHP_URL_PATH))]);
            foreach ($target['phrases'] as $rank => $phrase) {
                $stems = self::stems($phrase);
                if (count($stems) < 2) {
                    continue;
                }
                // Another page is the page for this search (or for every word of a part of it): a link on these words belongs there.
                if (self::belongsElsewhere($stems, $owners, $target['id'])) {
                    continue;
                }
                foreach ($index[$stems[0]] ?? [] as [$p, $w]) {
                    $source = $paragraphs[$p]['page_id'];
                    if ($source === $target['id'] || isset($exclude[$source]) || isset($linked[$source . ':' . $target['id']]) || isset($best[$source])) {
                        continue;
                    }
                    if (!self::matches($words[$p], $w, $stems, $paragraphs[$p]['text'])) {
                        continue;
                    }
                    if (LinkIntent::conflict($purpose, $paragraphs[$p]['text']) !== null) {
                        $leftOut++;
                        continue;
                    }
                    $text = $paragraphs[$p]['text'];
                    $from = $words[$p][$w][1];
                    $to = $words[$p][$w + count($stems) - 1][2];
                    // Searches earlier in the list (the topic name first) weigh a little more.
                    $best[$source] = [
                        'source_page_id' => $source,
                        'target_page_id' => $target['id'],
                        'cluster_id' => $target['cluster_id'],
                        'topic' => $target['topic'],
                        'anchor' => mb_substr(substr($text, $from, $to - $from), 0, self::ANCHOR_LENGTH),
                        'paragraph' => $text,
                        'score' => round(log1p(max(0.0, $target['demand'])) * (1 + log1p($pages[$source]['inbound'])) * (1 - min(0.5, $rank * 0.05)), 4),
                    ];
                }
            }
            usort($best, static fn(array $a, array $b): int => [$b['score'], $a['source_page_id']] <=> [$a['score'], $b['source_page_id']]);
            array_push($candidates, ...array_slice($best, 0, self::PER_TARGET));
        }

        usort($candidates, static fn(array $a, array $b): int => [$b['score'], $a['target_page_id'], $a['source_page_id']] <=> [$a['score'], $b['target_page_id'], $b['source_page_id']]);
        $perSource = [];
        $out = [];
        foreach ($candidates as $candidate) {
            if (($perSource[$candidate['source_page_id']] ?? 0) >= self::PER_SOURCE) {
                continue;
            }
            $perSource[$candidate['source_page_id']] = ($perSource[$candidate['source_page_id']] ?? 0) + 1;
            $out[] = $candidate;
            if (count($out) >= self::MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * The folded words of a search or of an address segment ("triploid-seeds"), joined by spaces:
     * the key of $dedicated.
     */
    public static function stemKey(string $text): string
    {
        return implode(' ', self::stems(str_replace(['-', '_', '+'], ' ', $text)));
    }

    /**
     * @param list<string> $stems
     * @param list<array{0: array<string, int>, 1: int}> $owners
     */
    private static function belongsElsewhere(array $stems, array $owners, int $target): bool
    {
        $words = array_flip($stems);
        foreach ($owners as [$set, $page]) {
            if ($page !== $target && count($set) >= 2 && array_diff_key($set, $words) === []) {
                return true;
            }
        }

        return false;
    }

    /** A sentence-like paragraph: enough words and no SKU-style product codes. */
    private static function prose(string $text): bool
    {
        return preg_match_all('/[\p{L}\p{N}]+/u', $text) >= self::MIN_WORDS
            && preg_match('/\bSKU\b|\b[A-Z]{2,}(?:-[A-Z0-9]{2,}){2,}\b/u', $text) !== 1;
    }

    /** @return list<string> */
    private static function stems(string $text): array
    {
        return array_map(static fn(string $t): string => QueryPatterns::stem(mb_strtolower($t)), QueryPatterns::tokens($text));
    }

    /**
     * Consecutive words with these stems, separated by spaces or hyphens only.
     *
     * @param array<int, array{0: string, 1: int, 2: int}> $words
     * @param list<string> $stems
     */
    private static function matches(array $words, int $start, array $stems, string $text): bool
    {
        foreach ($stems as $i => $stem) {
            if (($words[$start + $i][0] ?? null) !== $stem) {
                return false;
            }
            if ($i > 0 && preg_match('/^[\s\x{00A0}\-‐‑]+$/u', substr($text, $words[$start + $i - 1][2], $words[$start + $i][1] - $words[$start + $i - 1][2])) !== 1) {
                return false;
            }
        }

        return true;
    }
}

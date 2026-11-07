<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/**
 * Groups keywords into topics that one page can target.
 *
 * - When both keywords have a stored results page, the results decide:
 *   three or more shared top-10 URLs join them, one or none keep them apart
 *   (Google treats them as different topics even if the words overlap).
 * - Otherwise their core words decide: at least two core words each and a
 *   Jaccard overlap of at least 0.5, after dropping modifiers such as
 *   best, cheap, near me or how to and reducing plurals.
 *
 * Question frames ("how long does … last", "types of …", "how much …") are
 * not subjects: the overlap is measured on the remaining subject words, so
 * "how long does a pepper harvest last" and "how long does a mushroom harvest last"
 * stay apart. A keyword with a single subject word only joins one with the
 * same subject and question frame ("types of pepper" and "pepper types", not
 * "how long does pepper last").
 *
 * Single-word keywords never bridge two groups, so "shoes" cannot chain
 * "red shoes" and "shoe repair" into one topic. Groups whose wording asks
 * for different things (a tool, a purchase or comparison, an explanation,
 * a local business) are never joined, not even through a neutral keyword:
 * "running shoe size calculator" and "best running shoes" get different
 * results pages. The topic is named after its keyword with the most demand.
 */
final class KeywordClusterer
{
    public const int SERP_JOIN = 3;
    public const int SERP_SPLIT = 1;
    public const float JACCARD = 0.5;
    /** Words too common to compare on (a word in more keywords than this is skipped as a link candidate). */
    private const int MAX_POSTINGS = 150;
    /** Question-frame words (stemmed): they shape a question but never say what it is about. */
    public const array FRAME = ['long', 'last', 'type', 'kind', 'much', 'many', 'far', 'often', 'take', 'get', 'make', 'use', 'work', 'mean', 'difference', 'between', 'way', 'need', 'stay'];
    private const array IGNORED = ['a', 'an', 'the', 'and', 'or', 'of', 'for', 'to', 'in', 'on', 'at', 'with', 'by', 'from', 'my', 'your', 'is', 'are', 'what', 'how', 'why', 'when', 'where', 'which', 'who', 'do', 'does', 'can', 'best', 'top', 'cheap', 'cheapest', 'free', 'online', 'near', 'me', 'buy', 'price', 'cost', 'review', 'vs', 'versus', 'new', 'good', 'great', 'easy', 'guide', 'tip', 'idea', 'example'];

    /**
     * @param array<string, float> $keywords normalized keyword => demand (volume or impressions)
     * @param array<string, list<string>> $serps normalized keyword => top result URLs
     * @return array<string, array{label: string, size: int}> keyword => its topic
     */
    public function cluster(array $keywords, array $serps = []): array
    {
        $names = array_keys($keywords);
        $core = [];
        $postings = [];
        foreach ($names as $i => $keyword) {
            $core[$i] = self::core((string) $keyword);
            foreach ($core[$i] as $token) {
                $postings[$token][] = $i;
            }
        }
        $parent = array_keys($names);
        /** @var array<int, array<string, true>> $families intent families per group root */
        $families = [];
        foreach ($names as $i => $keyword) {
            $family = self::family((string) $keyword);
            $families[$i] = $family !== null ? [$family => true] : [];
        }
        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $parent[$i] = $parent[$parent[$i]];
                $i = $parent[$i];
            }
            return $i;
        };
        $checked = [];
        foreach ($postings as $members) {
            if (count($members) < 2 || count($members) > self::MAX_POSTINGS) {
                continue;
            }
            foreach ($members as $x => $a) {
                foreach (array_slice($members, $x + 1) as $b) {
                    $pair = $a . ':' . $b;
                    if (isset($checked[$pair])) {
                        continue;
                    }
                    $checked[$pair] = true;
                    $rootA = $find($a);
                    $rootB = $find($b);
                    if ($rootA === $rootB) {
                        continue;
                    }
                    $merged = $families[$rootA] + $families[$rootB];
                    if (count($merged) > 1 || !$this->joins((string) $names[$a], (string) $names[$b], $core[$a], $core[$b], $serps)) {
                        continue;
                    }
                    $parent[$rootA] = $rootB;
                    $families[$rootB] = $merged;
                }
            }
        }
        // Searches with stored results pages that share enough URLs belong
        // together even when their words are too common to compare
        // ("tomato seeds" and "organic seeds" on a seed shop).
        $withSerp = array_values(array_filter(array_keys($names), static fn(int $i): bool => count($serps[(string) $names[$i]] ?? []) >= 5));
        foreach ($withSerp as $x => $a) {
            foreach (array_slice($withSerp, $x + 1) as $b) {
                $rootA = $find($a);
                $rootB = $find($b);
                if ($rootA === $rootB || isset($checked[$a . ':' . $b])) {
                    continue;
                }
                $merged = $families[$rootA] + $families[$rootB];
                $shared = count(array_intersect(array_unique($serps[(string) $names[$a]]), array_unique($serps[(string) $names[$b]])));
                if (count($merged) <= 1 && $shared >= self::SERP_JOIN) {
                    $parent[$rootA] = $rootB;
                    $families[$rootB] = $merged;
                }
            }
        }
        $groups = [];
        foreach ($names as $i => $keyword) {
            $groups[$find($i)][] = (string) $keyword;
        }
        $result = [];
        foreach ($groups as $members) {
            usort($members, static fn(string $a, string $b): int => [$keywords[$b], mb_strlen($a), $a] <=> [$keywords[$a], mb_strlen($b), $b]);
            foreach ($members as $member) {
                $result[$member] = ['label' => $members[0], 'size' => count($members)];
            }
        }

        return $result;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @param array<string, list<string>> $serps
     */
    private function joins(string $first, string $second, array $a, array $b, array $serps): bool
    {
        $left = $serps[$first] ?? [];
        $right = $serps[$second] ?? [];
        if (count($left) >= 5 && count($right) >= 5) {
            $shared = count(array_intersect(array_unique($left), array_unique($right)));
            if ($shared >= self::SERP_JOIN) {
                return true;
            }
            if ($shared <= self::SERP_SPLIT) {
                return false;
            }
        }
        if (count($a) < 2 || count($b) < 2) {
            return false;
        }
        $subjectA = array_diff($a, self::FRAME);
        $subjectB = array_diff($b, self::FRAME);
        $shared = count(array_intersect($subjectA, $subjectB));
        $union = count(array_unique([...$subjectA, ...$subjectB]));
        // One subject word says too little to join on overlap alone: subject and question frame must then match.
        if (count($subjectA) < 2 || count($subjectB) < 2) {
            $frameA = array_values(array_intersect($a, self::FRAME));
            $frameB = array_values(array_intersect($b, self::FRAME));
            sort($frameA);
            sort($frameB);

            return $shared > 0 && $shared === $union && $frameA === $frameB;
        }

        return $shared / $union >= self::JACCARD;
    }

    /** What the wording asks for, or null when it is neutral (a bare topic). */
    public static function family(string $keyword): ?string
    {
        return match (QueryPatterns::intent($keyword)) {
            'tool' => 'tool',
            'local' => 'local',
            'commercial', 'transactional' => 'buy',
            'informational_simple', 'informational_deep' => 'learn',
            default => null,
        };
    }

    /** @return list<string> */
    public static function core(string $keyword): array
    {
        $tokens = array_filter(QueryPatterns::tokens(Keyword::normalize($keyword)), static fn(string $t): bool => !in_array($t, self::IGNORED, true) && preg_match('/^(19|20)\d\d$/', $t) !== 1);

        return array_values(array_unique($tokens));
    }
}

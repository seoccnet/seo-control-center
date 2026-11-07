<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * What kind of page Google ranks for a search (ADR-038), weighted by
 * position (a result at position p weighs 11 - p, so the top results count
 * most): dominant when one type holds at least 60 %, mixed when two types
 * each hold at least 25 %, otherwise fragmented. "Other" never dominates.
 */
final class ResultsFormat
{
    public const string DOMINANT = 'dominant';
    public const string MIXED = 'mixed';
    public const string FRAGMENTED = 'fragmented';
    public const float DOMINANT_SHARE = 0.6;
    public const float MIXED_SHARE = 0.25;

    /**
     * @param list<array{position: int, type: string}> $results top 10
     * @return array{verdict: string, types: list<string>, shares: array<string, float>, explanation: string}
     */
    public static function decide(array $results): array
    {
        $weights = [];
        foreach ($results as $r) {
            if ($r['position'] >= 1 && $r['position'] <= 10) {
                $weights[$r['type']] = ($weights[$r['type']] ?? 0) + (11 - $r['position']);
            }
        }
        $total = array_sum($weights);
        if ($total === 0) {
            return ['verdict' => self::FRAGMENTED, 'types' => [], 'shares' => [], 'explanation' => 'No results to compare.'];
        }
        $shares = array_map(static fn(int $w): float => round($w / $total, 3), $weights);
        arsort($shares);
        $ranked = array_keys(array_filter($shares, static fn(float $s, string $t): bool => $t !== SerpFormat::OTHER, ARRAY_FILTER_USE_BOTH));
        $first = $ranked[0] ?? null;
        $second = $ranked[1] ?? null;
        $label = static fn(string $t): string => self::label($t);
        if ($first !== null && $shares[$first] >= self::DOMINANT_SHARE) {
            return ['verdict' => self::DOMINANT, 'types' => [$first], 'shares' => $shares, 'explanation' => sprintf('Google ranks %s here (%d %% of the weighted top 10).', $label($first) . 's', (int) round($shares[$first] * 100))];
        }
        if ($first !== null && $second !== null && $shares[$first] >= self::MIXED_SHARE && $shares[$second] >= self::MIXED_SHARE) {
            return ['verdict' => self::MIXED, 'types' => [$first, $second], 'shares' => $shares, 'explanation' => sprintf('Both formats rank: %s (%d %%) and %s (%d %%).', $label($first) . 's', (int) round($shares[$first] * 100), $label($second) . 's', (int) round($shares[$second] * 100))];
        }

        return ['verdict' => self::FRAGMENTED, 'types' => $first === null ? [] : [$first], 'shares' => $shares, 'explanation' => 'No page type clearly holds this results page; treat the comparison with caution.'];
    }

    /** The label with its indefinite article: "an article or guide", "a product page". */
    public static function a(string $type): string
    {
        $label = self::label($type);

        return (preg_match('/^[aeiou]/i', $label) === 1 ? 'an ' : 'a ') . $label;
    }

    public static function label(string $type): string
    {
        return match ($type) {
            SerpFormat::PRODUCT => 'product page',
            SerpFormat::CATEGORY => 'category page',
            SerpFormat::REVIEW => 'comparison or best-of page',
            SerpFormat::ARTICLE => 'article',
            SerpFormat::TOOL => 'tool',
            SerpFormat::FORUM => 'forum thread',
            SerpFormat::VIDEO => 'video',
            SerpFormat::REFERENCE => 'reference page',
            SerpFormat::LOCAL => 'local page',
            SerpFormat::SERVICE => 'service page',
            SerpFormat::HOME => 'home page',
            default => 'other page',
        };
    }
}

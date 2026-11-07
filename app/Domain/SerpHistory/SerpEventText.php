<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SerpHistory;

use SeoControlCenter\Domain\Comparison\ResultsFormat;

/**
 * Plain-language wording of results-page changes, shared by the page,
 * alerts and MCP.
 */
final class SerpEventText
{
    public const array LABELS = [
        SerpChangeDetector::FEATURE_ADDED => 'Feature appeared',
        SerpChangeDetector::FEATURE_REMOVED => 'Feature disappeared',
        SerpChangeDetector::COMPETITOR_ENTERED => 'Competitor entered the top 10',
        SerpChangeDetector::COMPETITOR_LEFT => 'Competitor left the top 10',
        SerpChangeDetector::NEW_URL => 'New URL in the top 10',
        SerpChangeDetector::OWN_ENTERED => 'You entered the top 10',
        SerpChangeDetector::OWN_LEFT => 'You left the top 10',
        SerpChangeDetector::OWN_MOVED => 'Your position moved',
        SerpChangeDetector::FORMAT_SHIFT => 'Page type shifted',
    ];

    public static function feature(string $feature): string
    {
        return match ($feature) {
            'ai_overview' => 'AI Overview',
            'featured_snippet' => 'featured snippet',
            'people_also_ask' => 'People also ask',
            'local_pack' => 'local pack',
            'top_stories' => 'top stories',
            'shopping', 'popular_products' => 'shopping results',
            default => str_replace('_', ' ', $feature),
        };
    }

    public static function summary(SerpEvent $e): string
    {
        $d = $e->detail;
        $pos = static fn(mixed $p): string => is_int($p) ? '#' . $p : 'not in the top 100';

        return match ($e->kind) {
            SerpChangeDetector::FEATURE_ADDED => sprintf('%s appeared for “%s”', ucfirst(self::feature($e->subject)), $e->keyword),
            SerpChangeDetector::FEATURE_REMOVED => sprintf('%s disappeared for “%s”', ucfirst(self::feature($e->subject)), $e->keyword),
            SerpChangeDetector::COMPETITOR_ENTERED => sprintf('%s entered the top 10 for “%s” at %s%s', $e->subject, $e->keyword, $pos($d['position'] ?? null), ($d['above_you'] ?? false) === true ? ', above you' : ''),
            SerpChangeDetector::COMPETITOR_LEFT => sprintf('%s left the top 10 for “%s” (was %s)', $e->subject, $e->keyword, $pos($d['position'] ?? null)),
            SerpChangeDetector::NEW_URL => sprintf('%s now ranks %s for “%s”', ($d['own'] ?? false) === true ? 'Your page ' . $e->subject : $e->subject, $pos($d['position'] ?? null), $e->keyword),
            SerpChangeDetector::OWN_ENTERED => sprintf('You entered the top 10 for “%s”: %s → %s', $e->keyword, $pos($d['from'] ?? null), $pos($d['to'] ?? null)),
            SerpChangeDetector::OWN_LEFT => sprintf('You left the top 10 for “%s”: %s → %s', $e->keyword, $pos($d['from'] ?? null), $pos($d['to'] ?? null)),
            SerpChangeDetector::OWN_MOVED => sprintf('Your position for “%s” moved %s → %s', $e->keyword, $pos($d['from'] ?? null), $pos($d['to'] ?? null)),
            SerpChangeDetector::FORMAT_SHIFT => sprintf('The top 10 for “%s” shifted from %s to %s', $e->keyword, self::type((string) ($d['from'] ?? '')), self::type((string) ($d['to'] ?? ''))),
            default => $e->kind . ': ' . $e->subject,
        };
    }

    private static function type(string $type): string
    {
        return in_array($type, [ResultsFormat::MIXED, ResultsFormat::FRAGMENTED], true) ? $type . ' page types' : ResultsFormat::label($type) . 's';
    }
}

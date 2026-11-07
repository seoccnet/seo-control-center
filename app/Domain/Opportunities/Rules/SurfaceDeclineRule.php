<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;
use SeoControlCenter\Domain\SearchConsole\SearchType;

/**
 * Clicks from another Google surface (Discover, Google News, News tab,
 * image or video search) fell by at least 30 % against the preceding window,
 * with the same thresholds as page traffic declines.
 */
final class SurfaceDeclineRule implements OpportunityRule
{
    public function requires(?Opportunity $opportunity = null): array
    {
        return $opportunity !== null ? SignalRequirements::for('traffic_decline', $opportunity->subject) : ['search_console_surfaces'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->surfaces as $key => $window) {
            $type = SearchType::tryFrom((string) $key);
            $now = $window['now'];
            $previous = $window['previous'];
            if ($type === null || $previous->clicks < TrafficDeclineRule::MIN_PREVIOUS_CLICKS || $now->clicks > $previous->clicks * TrafficDeclineRule::MAX_RATIO) {
                continue;
            }
            $lost = ($previous->clicks - $now->clicks) * Format::MONTH_FACTOR;
            $found[] = new Opportunity(
                OpportunityType::TrafficDecline,
                'surface:' . $type->value,
                sprintf('%s clicks fell by %s', $type->label(), Format::percent(1 - $now->clicks / $previous->clicks)),
                self::advice($type),
                [
                    ['label' => 'Surface', 'value' => $type->label()],
                    ['label' => 'Clicks', 'value' => Format::int($previous->clicks) . ' → ' . Format::int($now->clicks) . ' (' . ($signals->searchConsoleWindow ?? 'last 28 days') . ' vs the 28 days before)'],
                    ['label' => 'Impressions', 'value' => Format::int($previous->impressions) . ' → ' . Format::int($now->impressions)],
                    ...($type->hasPosition() ? [['label' => 'Average position', 'value' => Format::position($previous->position) . ' → ' . Format::position($now->position)]] : []),
                ],
                Opportunity::impactFromClicks($lost),
                0,
                Opportunity::EFFORT_MEDIUM,
                estimatedClicks: $lost,
            );
        }

        return $found;
    }

    private static function advice(SearchType $type): string
    {
        return match ($type) {
            SearchType::Discover, SearchType::GoogleNews => 'This traffic depends on fresh, timely content and is naturally volatile. Compare publishing frequency, large and original images (at least 1200 px wide with max-image-preview:large), page experience and any manual actions before changing content.',
            SearchType::News => 'Check that recent articles are indexed quickly, carry clear dates and bylines, and are listed in a news sitemap; compare publishing frequency with the previous period.',
            SearchType::Image => 'Check that important images are still crawlable and indexed: unchanged URLs, descriptive file names and alt text, image sitemaps, and no robots.txt or CDN rules blocking them.',
            SearchType::Video => 'Check that video pages are still indexed and that each video has a crawlable thumbnail, structured data and, ideally, a video sitemap.',
        };
    }
}

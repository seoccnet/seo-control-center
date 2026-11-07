<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;

/**
 * Merge near-duplicate pages (0.44.0): two pages of the latest site audit
 * whose body text is nearly the same, whose purpose looks alike, and of
 * which at least one is shown in search. Folding the weaker page into the
 * stronger one, with a permanent redirect, gives search engines one clear
 * page instead of two competing copies. Pairs a person decided to keep
 * separate in the overlap review, and pairs with a product page (0.44.1),
 * are left out by the collector.
 */
final class DuplicatePagesRule implements OpportunityRule
{
    public const string PREFIX = 'duplicate:';
    public const int LIMIT = 20;

    public function requires(?Opportunity $opportunity = null): array
    {
        return SignalRequirements::for('cannibalization', $opportunity !== null ? $opportunity->subject : self::PREFIX);
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $search = [];
        foreach ($signals->pages as $row) {
            $search[self::key($row->key)] = $row;
        }
        $found = [];
        foreach ($signals->nearDuplicates as $pair) {
            $pages = [];
            foreach ($pair['members'] as $url) {
                $row = $search[self::key($url)] ?? null;
                $pages[] = ['url' => $url, 'clicks' => $row?->metrics->clicks ?? 0, 'impressions' => $row?->metrics->impressions ?? 0];
            }
            $impressions = array_sum(array_column($pages, 'impressions'));
            if ($impressions === 0) {
                continue; // neither page is shown in search: nothing competes yet
            }
            usort($pages, static fn(array $a, array $b): int => [$b['clicks'], $b['impressions'], strlen($a['url'])] <=> [$a['clicks'], $a['impressions'], strlen($b['url'])]);
            $keep = $pages[0];
            $merge = array_slice($pages, 1);
            $clicks = array_sum(array_column($pages, 'clicks'));
            $found[] = [$impressions, new Opportunity(
                OpportunityType::Cannibalization,
                self::PREFIX . $pair['key'],
                sprintf('Merge near-duplicate pages into %s', Format::path($keep['url'])),
                sprintf(
                    '%s and %s have nearly the same text, so search engines see two pages competing for the same searches. Keep %s, move anything unique from the other page into it, and redirect the other page there permanently (301). Check first in Research → Content inventory → Overlap review that the pages really serve the same purpose.',
                    Format::path($keep['url']),
                    implode(', ', array_map(static fn(array $p): string => Format::path($p['url']), $merge)),
                    Format::path($keep['url']),
                ),
                [
                    ['label' => 'Page', 'value' => $keep['url'] . ' (keep: most clicks, then impressions)'],
                    ['label' => 'Merge into it', 'value' => implode(', ', array_column($merge, 'url'))],
                    ['label' => 'Same text', 'value' => $pair['evidence'][0] ?? 'Nearly the same body text'],
                    ['label' => 'Search data (28 days)', 'value' => implode('; ', array_map(static fn(array $p): string => sprintf('%s: %s clicks, %s impressions', Format::path($p['url']), Format::int($p['clicks']), Format::int($p['impressions'])), $pages))],
                    ['label' => 'Overlap review', 'value' => $pair['decision'] === 'merge_planned' ? 'Merge planned' : 'Not decided yet'],
                    ['label' => 'Limits', 'value' => $pair['uncertainty']],
                ],
                max(25, Opportunity::impactFromClicks($clicks * Format::MONTH_FACTOR)),
                0,
                Opportunity::EFFORT_MEDIUM,
                urlsTouched: count($pages),
            )];
        }
        usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column(array_slice($found, 0, self::LIMIT), 1);
    }

    /** Search Console and the crawler may differ in the trailing slash and the host's www. */
    private static function key(string $url): string
    {
        $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $query = parse_url($url, PHP_URL_QUERY);

        return $host . ($path === '' ? '/' : $path) . (is_string($query) && $query !== '' ? '?' . $query : '');
    }
}

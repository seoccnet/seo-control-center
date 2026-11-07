<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;

/**
 * Topic pages that do not link to their topic's main page (0.42.0). Topics &
 * owners names the page that should rank for a topic (the pillar) and the
 * other pages Google shows for it. A supporting page with real impressions
 * whose text links nowhere to the pillar misses the cross-link that published
 * tests of topic clusters found to help the pillar rank. Only links in the
 * text and related cards count; menus and footers do not. Pages the latest
 * site audit did not crawl are skipped: their links are unknown.
 *
 * The main page must be trustworthy (0.42.1): set by a person, or inferred
 * from Search Console with at least MIN_SHARE of the topic and an article
 * address. An inferred product or category page that merely leads a topic is
 * not a hub that the topic's articles should point to.
 */
final class PillarLinksRule implements OpportunityRule
{
    public const int MIN_IMPRESSIONS = 50;
    public const int LIMIT = 20;
    public const float MIN_SHARE = 0.5;

    public function requires(?Opportunity $opportunity = null): array
    {
        return SignalRequirements::for('internal_links', $opportunity !== null ? $opportunity->subject : 'pillar:');
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $panel = $signals->panel;
        if ($panel === null || $signals->topics === [] || ($signals->coverage['internal_links']['status'] ?? $panel->coverage['internal_links']['status'] ?? 'missing') !== 'available') {
            return [];
        }
        $crawled = [];
        foreach ($panel->auditPages as $page) {
            $crawled[self::key($page['url'])] = !$page['noindex'];
        }
        $found = [];
        foreach ($signals->topics as $topic) {
            $owner = self::key($topic['owner']);
            if (!($crawled[$owner] ?? false) || !self::trusted($topic)) {
                continue;
            }
            $missing = [];
            foreach ($topic['pages'] as $page) {
                $key = self::key($page['url']);
                if ($key === $owner || $page['impressions'] < self::MIN_IMPRESSIONS || !isset($crawled[$key])) {
                    continue;
                }
                $targets = array_map(self::key(...), $panel->bodyOutgoing[$page['url']] ?? []);
                if (!in_array($owner, $targets, true)) {
                    $missing[] = $page;
                }
            }
            if ($missing === []) {
                continue;
            }
            $clicks = array_sum(array_column($missing, 'clicks'));
            $found[] = [array_sum(array_column($missing, 'impressions')), new Opportunity(
                OpportunityType::InternalLinks,
                'pillar:' . $topic['owner'],
                sprintf('%d %s about “%s” %s not link to its main page', count($missing), count($missing) === 1 ? 'page' : 'pages', $topic['label'], count($missing) === 1 ? 'does' : 'do'),
                'Topics & owners names ' . Format::path($topic['owner']) . ' as the main page for this topic, but other pages Google shows for it never link to it from their text. Add a contextual link from each to the main page, with words that describe the topic, and link back from the main page.',
                [
                    ['label' => 'Topic', 'value' => $topic['label']],
                    ['label' => 'Page', 'value' => $topic['owner'] . ' (the topic’s main page)'],
                    ['label' => 'Main page from', 'value' => $topic['manual'] ? 'Set by a person in Topics & owners' : sprintf('Search Console: it gets %s of the topic’s searches', Format::percent((float) $topic['share']))],
                    ['label' => 'Pages without a link in the text', 'value' => implode(', ', array_map(static fn(array $p): string => Format::path($p['url']) . ' (' . Format::int($p['impressions']) . ' impressions)', $missing))],
                    ['label' => 'Counted links', 'value' => 'Links in the text and related cards of the latest site audit; menus and footers do not count.'],
                ],
                Opportunity::impactFromClicks((float) $clicks),
                0,
                Opportunity::EFFORT_LOW,
                urlsTouched: count($missing),
            )];
        }
        usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column(array_slice($found, 0, self::LIMIT), 1);
    }

    /** @param array{owner: string, manual: bool, share: ?float} $topic */
    public static function trusted(array $topic): bool
    {
        return $topic['manual'] || ($topic['share'] !== null && $topic['share'] >= self::MIN_SHARE && ContentRefreshRule::article($topic['owner']));
    }

    private static function key(string $url): string
    {
        return rtrim(strtolower($url), '/');
    }
}

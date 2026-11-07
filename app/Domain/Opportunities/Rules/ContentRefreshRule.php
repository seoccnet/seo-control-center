<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Articles that still earn search demand but have not been updated for a
 * year (0.41.0): at least MIN_IMPRESSIONS in the current window, an average
 * position within reach (MAX_POSITION), and a sitemap last-modified date
 * older than MIN_AGE_DAYS. Refreshing facts and expanding sections is the
 * best-supported content change in published tests, so the page is worth
 * updating before it declines. No click gain is estimated: the clicks the
 * page earns now set its impact, as what the refresh protects.
 *
 * Only article-like addresses count (blog, news, guide and similar folders):
 * product, category and listing pages change for other reasons. Pages
 * without a sitemap date are skipped, never assumed old.
 */
final class ContentRefreshRule implements OpportunityRule
{
    public const int MIN_IMPRESSIONS = 300;
    public const float MAX_POSITION = 20.0;
    public const int MIN_AGE_DAYS = 365;
    public const int LIMIT = 30;
    public const array ARTICLE_FOLDERS = ['blog', 'blogs', 'news', 'guide', 'guides', 'article', 'articles', 'magazine', 'journal', 'learn', 'knowledge', 'resources', 'insights', 'posts', 'post', 'how-to', 'tips'];

    public function requires(?Opportunity $opportunity = null): array
    {
        return ['search_console', 'page_dates'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        if ($signals->pageUpdates === [] || $signals->analysisDate === null) {
            return [];
        }
        $cutoff = (new \DateTimeImmutable($signals->analysisDate))->modify('-' . self::MIN_AGE_DAYS . ' days')->format('Y-m-d');
        $found = [];
        foreach ($signals->pages as $row) {
            $updated = $signals->pageUpdates[$row->key] ?? null;
            $m = $row->metrics;
            if ($updated === null || $updated > $cutoff || $m->impressions < self::MIN_IMPRESSIONS || $m->position <= 0 || $m->position > self::MAX_POSITION || !self::article($row->key)) {
                continue;
            }
            $age = (int) (new \DateTimeImmutable($updated))->diff(new \DateTimeImmutable($signals->analysisDate))->days;
            $found[] = [$m->impressions, new Opportunity(
                OpportunityType::ContentRefresh,
                'page:' . $row->key,
                sprintf('%s was last updated %s ago and still gets %s impressions', Format::path($row->key), self::age($age), Format::int($m->impressions)),
                'The page earns search demand, but its sitemap says it has not changed for over a year. Update the facts and figures, add the subtopics current top results cover, and keep what already ranks. Refreshing aging articles is among the best-tested content changes; measure it against a few similar pages you leave unchanged.',
                [
                    ['label' => 'Page', 'value' => $row->key],
                    ['label' => 'Last updated', 'value' => $updated . ' (sitemap last-modified date)'],
                    ['label' => 'Clicks', 'value' => Format::int($m->clicks) . ' (' . $signals->searchConsoleWindow . ')'],
                    ['label' => 'Impressions', 'value' => Format::int($m->impressions)],
                    ['label' => 'Average position', 'value' => Format::position($m->position)],
                    ['label' => 'Estimate', 'value' => 'No click gain is estimated; the clicks the page earns now are what the refresh protects.'],
                ],
                Opportunity::impactFromClicks((float) $m->clicks),
                0,
                Opportunity::EFFORT_MEDIUM,
            )];
        }
        usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column(array_slice($found, 0, self::LIMIT), 1);
    }

    public static function article(string $url): bool
    {
        $segments = array_map('strtolower', array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH)), static fn(string $s): bool => $s !== '')));
        // An article sits inside an article folder; the folder itself and its archives are listings.
        return count($segments) >= 2 && array_intersect(array_slice($segments, 0, -1), self::ARTICLE_FOLDERS) !== [] && !in_array($segments[count($segments) - 2], ['tag', 'tags', 'topic', 'topics', 'category', 'categories', 'page', 'author', 'authors'], true);
    }

    private static function age(int $days): string
    {
        return $days >= 730 ? intdiv($days, 365) . ' years' : intdiv($days, 30) . ' months';
    }
}

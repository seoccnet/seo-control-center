<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;

/**
 * Bing's own crawl counts: a clear drop in indexed pages over the last four
 * weeks, or a high share of crawl errors. Bing's index also serves Yahoo,
 * DuckDuckGo, Ecosia and the web search of ChatGPT and Copilot.
 */
final class BingCrawlRule implements OpportunityRule
{
    /** Days compared for the index trend. */
    public const int WINDOW_DAYS = 28;
    /** An index drop counts from this share and this many pages. */
    public const float MIN_DROP_SHARE = 0.10;
    public const int MIN_DROP_PAGES = 20;
    /** Crawl errors count from this share of crawled pages and this many errors in the window. */
    public const float MIN_ERROR_SHARE = 0.05;
    public const int MIN_ERRORS = 20;

    public function requires(?Opportunity $opportunity = null): array
    {
        return $opportunity !== null ? SignalRequirements::for('indexing', $opportunity->subject) : ['bing_crawl'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        if ($signals->bingCrawl === []) {
            return [];
        }
        $latest = $signals->bingCrawl[count($signals->bingCrawl) - 1];
        $from = new \DateTimeImmutable($latest['date'])->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d');
        $earlier = null;
        $window = [];
        foreach ($signals->bingCrawl as $day) {
            if ($day['date'] <= $from) {
                $earlier = $day; // the last day on or before the window start
            } else {
                $window[] = $day;
            }
        }
        $found = [];
        if ($earlier !== null && $earlier['inIndex'] > 0) {
            $drop = $earlier['inIndex'] - $latest['inIndex'];
            if ($drop >= self::MIN_DROP_PAGES && $drop >= $earlier['inIndex'] * self::MIN_DROP_SHARE) {
                $found[] = new Opportunity(
                    OpportunityType::Indexing,
                    'indexing:bing:index',
                    sprintf('Bing dropped %s of your pages from its index', Format::int($drop)),
                    'Bing reports fewer of your pages in its index than four weeks ago. Check Bing Webmaster Tools (Site Explorer, URL Inspection) for removed or excluded pages, and compare with recent changes: noindex, redirects, robots.txt, removed pages or thin content. A planned clean-up can explain a drop.',
                    [
                        ['label' => 'Pages in Bing’s index', 'value' => Format::int($earlier['inIndex']) . ' on ' . $earlier['date'] . ' → ' . Format::int($latest['inIndex']) . ' on ' . $latest['date']],
                        ['label' => 'Change', 'value' => '−' . Format::int($drop) . ' (' . Format::percent($drop / $earlier['inIndex']) . ')'],
                        ['label' => 'Crawl errors in the period', 'value' => Format::int(array_sum(array_column($window, 'errors')))],
                    ],
                    (int) max(35, min(80, round(25 * log10(1 + $drop)))),
                    0,
                    Opportunity::EFFORT_MEDIUM,
                );
            }
        }
        $crawled = array_sum(array_column($window, 'crawled'));
        $errors = array_sum(array_column($window, 'errors'));
        if ($crawled > 0 && $errors >= self::MIN_ERRORS && $errors >= $crawled * self::MIN_ERROR_SHARE) {
            $found[] = new Opportunity(
                OpportunityType::Indexing,
                'indexing:bing:errors',
                sprintf('Reduce Bing crawl errors (%s of crawled pages)', Format::percent($errors / $crawled)),
                'Bingbot hits errors on a noticeable share of the pages it crawls. Errors waste crawl budget and can keep pages out of the index. Find the failing URLs in Bing Webmaster Tools (Crawl information), fix server errors first, then redirect or remove links to pages that no longer exist.',
                [
                    ['label' => 'Crawl errors', 'value' => Format::int($errors) . ' of ' . Format::int($crawled) . ' crawled pages since ' . ($window[0]['date'] ?? $latest['date'])],
                    ['label' => 'Server errors (5xx)', 'value' => Format::int(array_sum(array_column($window, 'code5xx')))],
                    ['label' => 'Not found and other 4xx', 'value' => Format::int(array_sum(array_column($window, 'code4xx')))],
                    ['label' => 'Blocked by robots.txt', 'value' => Format::int(array_sum(array_column($window, 'blocked')))],
                ],
                (int) max(30, min(70, round(20 * log10(1 + $errors)))),
                0,
                Opportunity::EFFORT_MEDIUM,
            );
        }

        return $found;
    }
}

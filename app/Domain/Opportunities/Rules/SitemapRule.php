<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;

/**
 * Sitemaps Search Console processed with errors or warnings. Errors can stop
 * Google from reading listed URLs; warnings usually mean URLs Google cannot
 * use (blocked, redirected or not found) and dilute the sitemap.
 */
final class SitemapRule implements OpportunityRule
{
    public function requires(?Opportunity $opportunity = null): array
    {
        return $opportunity !== null ? SignalRequirements::for('indexing', $opportunity->subject) : ['sitemaps'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->sitemaps as $sitemap) {
            if (!$sitemap->hasProblems()) {
                continue;
            }
            $errors = $sitemap->errors > 0;
            $found[] = new Opportunity(
                OpportunityType::Indexing,
                'indexing:sitemap:' . $sitemap->url,
                ($errors ? 'Fix errors in sitemap ' : 'Clean up warnings in sitemap ') . Format::path($sitemap->url),
                $errors
                    ? 'Search Console could not fully process this sitemap, so Google may not discover or recrawl the URLs it lists. Open Search Console → Sitemaps for the exact errors, fix the file (format, size, unreachable URL), then resubmit it.'
                    : 'Search Console accepted this sitemap with warnings, usually URLs it cannot use: blocked by robots.txt, redirected, or not found. List only final, indexable URLs that return 200, then resubmit it.',
                [
                    ['label' => 'Sitemap', 'value' => $sitemap->url],
                    ['label' => 'Errors', 'value' => Format::int($sitemap->errors)],
                    ['label' => 'Warnings', 'value' => Format::int($sitemap->warnings)],
                    ['label' => 'URLs submitted', 'value' => $sitemap->isIndex ? 'sitemap index (child sitemaps listed separately)' : Format::int($sitemap->submittedUrls)],
                    ['label' => 'Last read by Google', 'value' => $sitemap->isPending ? 'pending' : ($sitemap->lastDownloaded !== null ? $sitemap->lastDownloaded . ' UTC' : 'not yet')],
                ],
                $errors ? 45 : 25,
                0,
                Opportunity::EFFORT_LOW,
            );
        }

        return $found;
    }
}

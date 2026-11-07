<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * Tracked keywords where the project's ranking page is a different kind of
 * page than the one Google rewards: e.g. a blog post on a results page of
 * category pages. Content quality rarely beats a format mismatch, so the
 * fix is a page of the right format.
 *
 * Needs the latest stored results page with a dominant format (at least
 * half of the classified results and three of them), a classified page of
 * the project at positions 4-30, and at most one other result of the
 * project's format. Results pages led by home pages, forums, videos,
 * reference entries or local listings are left out: those are navigational,
 * zero-click or local findings, not a page to rebuild.
 */
final class FormatMismatchRule implements OpportunityRule
{
    public const int MIN_POSITION = 4;
    public const int MAX_POSITION = 30;
    public const array ACTIONABLE = [SerpFormat::PRODUCT, SerpFormat::CATEGORY, SerpFormat::REVIEW, SerpFormat::ARTICLE, SerpFormat::TOOL, SerpFormat::SERVICE];
    private const int TARGET_POSITION = 3;

    public function requires(?Opportunity $opportunity = null): array
    {
        return ['rankings', 'serp_landscape'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->keywords as $keyword) {
            $serp = $signals->serps[$keyword->keyword] ?? null;
            $position = $keyword->position;
            if ($serp === null || $keyword->url === null || $position === null || $position < self::MIN_POSITION || $position > self::MAX_POSITION) {
                continue;
            }
            $dominant = $serp->dominantFormat();
            if ($dominant === null || !in_array($dominant, self::ACTIONABLE, true)) {
                continue;
            }
            $own = $serp->resultOf($signals->domain);
            $format = SerpFormat::classify($keyword->url, $own !== null && $own['url'] === $keyword->url ? $own['title'] : null);
            $counts = $serp->formatCounts();
            if ($format === SerpFormat::OTHER || SerpFormat::compatible($format, $dominant) || ($counts[$format] ?? 0) - ($own !== null ? 1 : 0) > 1) {
                continue;
            }
            $volume = $keyword->searchVolume;
            $extra = ($volume ?? 0) * max(0.0, $signals->expectedCtr(self::TARGET_POSITION) - $signals->expectedCtr($position));
            $structural = in_array($dominant, [SerpFormat::PRODUCT, SerpFormat::CATEGORY, SerpFormat::TOOL], true) || in_array($format, [SerpFormat::PRODUCT, SerpFormat::CATEGORY], true);
            $found[] = new Opportunity(
                OpportunityType::FormatMismatch,
                'format:' . $keyword->publicId,
                sprintf('Google ranks %s for “%s”; your page is %s', SerpFormat::plural($dominant), $keyword->keyword, SerpFormat::withArticle($format)),
                sprintf(
                    'Most results for this search are %s, and your page at position %d is %s. Search engines rank the kind of page searchers expect, so improving the current page rarely closes this gap. Build or link %s, and let the current page support it.%s',
                    SerpFormat::plural($dominant),
                    $position,
                    SerpFormat::withArticle($format),
                    SerpFormat::action($dominant),
                    $volume === null ? ' The search volume is unknown, so the monthly gain cannot be estimated.' : '',
                ),
                [
                    ['label' => 'Keyword', 'value' => $keyword->keyword],
                    ['label' => 'Ranking URL', 'value' => $keyword->url],
                    ['label' => 'Position', 'value' => $position . ' (check of ' . $keyword->checkedOn . ')'],
                    ['label' => 'Your page format', 'value' => SerpFormat::label($format) . ' (from URL and title patterns)'],
                    ['label' => 'Top results', 'value' => $serp->describeFormats() . ', rank check of ' . $serp->checkedOn],
                    ['label' => 'Search volume', 'value' => $volume !== null ? Format::int($volume) . ' / month' : 'unknown'],
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis(self::TARGET_POSITION)],
                    ['label' => 'Est. extra clicks at position 3', 'value' => $volume !== null ? Format::int($extra) . ' / month, if a page of the right format reaches position 3' : 'unknown'],
                ],
                Opportunity::impactFromClicks($extra),
                0,
                $structural ? Opportunity::EFFORT_HIGH : Opportunity::EFFORT_MEDIUM,
                estimatedClicks: $volume === null ? null : $extra,
            );
        }

        return $found;
    }
}

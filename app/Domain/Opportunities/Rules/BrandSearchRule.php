<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Brand searches where the site is not first (0.50.0). People who search
 * the brand look for the site; anything above it takes those clicks. A
 * search counts as a brand search when one to three adjacent words of it,
 * written together, are a brand word, with or without a final "s"
 * ("northwind good" for northwindgoods). Reported as quick wins with the
 * subject brand:<query>; QuickWinRule leaves these searches to it.
 */
final class BrandSearchRule implements OpportunityRule
{
    /** Average positions up to this count as first. */
    public const float FIRST = 1.5;
    public const int MIN_IMPRESSIONS = 20;
    /** Words that describe a kind of site, not a brand (0.50.1). */
    private const array GENERIC_NAME_WORDS = ['web', 'online', 'internet', 'shop', 'webshop', 'store', 'site', 'website', 'page', 'project', 'my', 'our', 'the', 'new', 'main', 'seo', 'demo', 'test', 'blog', 'ecommerce'];

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['search_console'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $brands = $signals->brandTerms();
        if ($brands === []) {
            return [];
        }
        $found = [];
        foreach ($signals->queries as $row) {
            $m = $row->metrics;
            if ($m->impressions < self::MIN_IMPRESSIONS || $m->position <= self::FIRST || !self::isBrand($row->key, $brands)) {
                continue;
            }
            $position = max(1, (int) round($m->position));
            $extra = $m->impressions * Format::MONTH_FACTOR * max(0.0, $signals->expectedCtr(1) - $m->ctr());
            $found[] = new Opportunity(
                OpportunityType::QuickWin,
                'brand:' . $row->key,
                sprintf('Rank first for your brand search “%s” (now %s)', $row->key, Format::position($m->position)),
                'People who search your brand are looking for your site, and whatever ranks above you takes those clicks. Check what ranks above you for this search: a marketplace, a review site, an old page of your own, or a business with a similar name. Then make the brand unmistakable on your home page: the name as people write it in the title and main heading, Organization markup with the name and logo, and links from your social profiles.',
                [
                    ['label' => 'Query', 'value' => $row->key],
                    ['label' => 'Average position', 'value' => Format::position($m->position) . ' (' . $signals->searchConsoleWindow . ')'],
                    ['label' => 'Impressions', 'value' => Format::int($m->impressions)],
                    ['label' => 'Clicks', 'value' => Format::int($m->clicks)],
                    ['label' => 'Brand words', 'value' => implode(', ', $brands)],
                    ['label' => 'Est. extra clicks at position 1', 'value' => Format::int($extra) . ' / month'],
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis(1)],
                ],
                Opportunity::impactFromClicks($extra),
                0,
                $position <= 3 ? Opportunity::EFFORT_LOW : Opportunity::EFFORT_MEDIUM,
                estimatedClicks: $extra,
            );
        }

        return $found;
    }

    /** Whether a project name is made only of generic words, such as "Web Shop", and so says nothing about the brand (0.50.1). */
    public static function isGenericName(string $name): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', Keyword::normalize($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_diff($words, self::GENERIC_NAME_WORDS) === [];
    }

    /**
     * Whether a search names the brand: one to three adjacent words of it,
     * written together, equal a brand word with or without a final "s".
     *
     * @param list<string> $brands normalized brand words
     */
    public static function isBrand(string $query, array $brands): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', Keyword::normalize($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $variants = [];
        foreach ($brands as $brand) {
            $compact = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', Keyword::normalize($brand));
            if (mb_strlen($compact) >= 3) {
                $variants[$compact] = true;
                $variants[(string) preg_replace('/s$/u', '', $compact)] = true;
            }
        }
        $count = count($words);
        for ($i = 0; $i < $count; ++$i) {
            $joined = '';
            for ($j = $i; $j < min($count, $i + 3); ++$j) {
                $joined .= $words[$j];
                if (isset($variants[$joined]) || isset($variants[(string) preg_replace('/s$/u', '', $joined)])) {
                    return true;
                }
            }
        }

        return false;
    }
}

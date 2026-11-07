<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * Changes that tend to cost traffic, used wherever SEOcc suggests titles,
 * briefs or page changes: content briefs, action plans and the assistant's
 * instructions.
 */
final class SeoGuardrails
{
    /** Rules for titles and meta descriptions. */
    public const array TITLES = [
        'Keep the words that match the page’s intent; do not swap them for a higher-volume keyword variant.',
        'Keep useful synonyms in listing and category titles; removing them narrows what the page ranks for.',
        'Do not put fixed prices in titles; use only a dynamic “from” price that stays in sync with the page.',
        'Do not repeat keywords or stuff variants into titles, descriptions or body text.',
        'Write numbers as digits, not emoji, and use emoji in titles only after testing them on a few pages.',
    ];

    /** Rules for page structure and site-wide changes. */
    public const array PAGES = [
        'Keep breadcrumbs complete, including the link to the current page.',
        'Keep store-locator and multi-location map components on location pages.',
        'Do not move a search box above the main content or hero area without testing it first.',
        'Do not change existing URLs just to add keywords; redirects cost more than the keyword helps.',
        'Use AI-generated text only on specific pages, edited by a person, never as mass sitewide copy.',
        'Disavow links only when Search Console shows a manual action for unnatural links.',
        'Before changing a Business Profile primary category, check whether a more specific one exists.',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::TITLES, ...self::PAGES];
    }
}

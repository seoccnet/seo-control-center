<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\AiVisibility;

/**
 * Citation readiness (0.43.0): a checklist of what a page offers AI answers
 * and search features to quote, from what the latest site audit read on it.
 * Each item carries how well published tests back it, with the levels of
 * Playbook::TESTED: tested, varies or early.
 *
 * A check is null (unknown) when the page was crawled before the signal was
 * recorded; unknown checks count neither for nor against the page.
 *
 * The author and source checks apply to articles only (0.43.1): a product,
 * category or home page is not expected to name an author or cite sources,
 * so on those pages the two checks are null as well.
 */
final class CitationReadiness
{
    /** The opening paragraph counts as a direct answer between these word counts. */
    public const int LEAD_MIN = 20;
    public const int LEAD_MAX = 80;

    /** Structured data types that describe the site, not the page's content. */
    private const array SITE_TYPES = ['BreadcrumbList', 'WebSite', 'WebPage', 'Organization', 'SiteNavigationElement', 'SearchAction', 'ImageObject'];

    /** @var array<string, array{label: string, tested: string, hint: string}> */
    /** Checks that only apply to articles. */
    public const array ARTICLE_ONLY = ['author', 'sources'];

    public const array CHECKS = [
        'author' => ['label' => 'Names an author', 'tested' => 'tested', 'hint' => 'Show who wrote or checked the page, with a byline or author structured data.'],
        'structured_data' => ['label' => 'Structured data for the content', 'tested' => 'tested', 'hint' => 'Mark up the content itself (Article, Product, FAQPage, HowTo …), not only breadcrumbs.'],
        'sources' => ['label' => 'Links to sources', 'tested' => 'tested', 'hint' => 'Link the studies, data and official pages the text relies on, from the text itself.'],
        'figures' => ['label' => 'Facts with figures', 'tested' => 'tested', 'hint' => 'Give concrete numbers: percentages, prices, sizes, dates.'],
        'structure' => ['label' => 'Tables or lists', 'tested' => 'tested', 'hint' => 'Put comparisons in a table and steps or options in a list.'],
        'questions' => ['label' => 'Headings phrased as questions', 'tested' => 'varies', 'hint' => 'Phrase some H2 or H3 headings as the questions people ask.'],
        'updated' => ['label' => 'States when it was updated', 'tested' => 'varies', 'hint' => 'Add dateModified to the structured data (or article:modified_time) when the content changes.'],
        'lead' => ['label' => 'Opens with a short answer', 'tested' => 'early', 'hint' => 'Start with a ' . self::LEAD_MIN . ' to ' . self::LEAD_MAX . ' word paragraph that answers the main question.'],
    ];

    /**
     * @param list<string>|null $schemaTypes
     * @param array{questions: int, tables: int, lists: int, figures: int, lead_words: ?int, updated: bool}|null $citation
     * @param bool $article the page is an article (author and source checks apply)
     * @return list<array{key: string, label: string, tested: string, hint: string, passed: ?bool}>
     */
    public static function checks(?bool $hasAuthor, ?array $schemaTypes, ?int $sourceLinks, ?array $citation, bool $article = true): array
    {
        if (!$article) {
            $hasAuthor = null;
            $sourceLinks = null;
        }
        $lead = $citation['lead_words'] ?? null;
        $passed = [
            'author' => $hasAuthor,
            'structured_data' => $schemaTypes !== null ? array_diff($schemaTypes, self::SITE_TYPES) !== [] : null,
            'sources' => $sourceLinks !== null ? $sourceLinks > 0 : null,
            'figures' => $citation !== null ? $citation['figures'] > 0 : null,
            'structure' => $citation !== null ? $citation['tables'] + $citation['lists'] > 0 : null,
            'questions' => $citation !== null ? $citation['questions'] > 0 : null,
            'updated' => $citation !== null ? $citation['updated'] : null,
            'lead' => $citation !== null ? $lead !== null && $lead >= self::LEAD_MIN && $lead <= self::LEAD_MAX : null,
        ];
        $out = [];
        foreach (self::CHECKS as $key => $check) {
            $out[] = ['key' => $key, ...$check, 'passed' => $passed[$key]];
        }

        return $out;
    }

    /**
     * Passed and known checks, and the applicable checks still unknown (0.43.2):
     * signals the audit did not record yet, not the article-only checks of other pages.
     *
     * @param list<array{key: string, passed: ?bool}> $checks
     * @return array{passed: int, known: int, unknown: int}
     */
    public static function score(array $checks, bool $article = true): array
    {
        $known = array_values(array_filter($checks, static fn(array $c): bool => $c['passed'] !== null));
        $applicable = array_filter($checks, static fn(array $c): bool => $article || !in_array($c['key'], self::ARTICLE_ONLY, true));

        return [
            'passed' => count(array_filter($known, static fn(array $c): bool => $c['passed'] === true)),
            'known' => count($known),
            'unknown' => count($applicable) - count(array_filter($applicable, static fn(array $c): bool => $c['passed'] !== null)),
        ];
    }
}

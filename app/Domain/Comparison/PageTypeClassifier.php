<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * The page type of a result (ADR-038), decided before any content is
 * compared. Content signals from the fetched page come first (schema,
 * price, add-to-cart, product grid, byline, form, map); the URL, domain
 * and title patterns of SerpFormat decide when the page could not be read
 * or shows no signal. Every decision keeps the signals that made it.
 */
final class PageTypeClassifier
{
    public const string HIGH = 'high';
    public const string MEDIUM = 'medium';
    public const string LOW = 'low';

    /** Types whose pages are compared as content; the others still count in the results-page format. */
    public const array FETCHABLE = [SerpFormat::PRODUCT, SerpFormat::CATEGORY, SerpFormat::REVIEW, SerpFormat::ARTICLE, SerpFormat::TOOL, SerpFormat::SERVICE, SerpFormat::LOCAL, SerpFormat::HOME, SerpFormat::OTHER];

    /**
     * @return array{type: string, confidence: string, signals: list<string>}
     */
    public static function classify(string $url, ?string $title, ?PageStructure $page = null): array
    {
        $byUrl = SerpFormat::classify($url, $title ?? $page?->title);
        $path = (string) parse_url($url, PHP_URL_PATH);
        // The site root, also per language or country (/us/, /en-gb/), is a home page whatever its title says.
        $root = $path === '' || $path === '/' || preg_match('#^/[a-z]{2}(-[a-z]{2})?/?$#i', $path) === 1;
        if (in_array($byUrl, [SerpFormat::FORUM, SerpFormat::VIDEO, SerpFormat::REFERENCE], true)) {
            return ['type' => $byUrl, 'confidence' => self::HIGH, 'signals' => ['site: ' . (string) parse_url($url, PHP_URL_HOST)]];
        }
        $content = $page !== null && $page->readable() ? self::fromContent($page) : null;
        if ($content === null) {
            if ($root) {
                return ['type' => SerpFormat::HOME, 'confidence' => self::MEDIUM, 'signals' => ['home page address']];
            }

            return ['type' => $byUrl, 'confidence' => $byUrl === SerpFormat::OTHER ? self::LOW : self::MEDIUM, 'signals' => [$byUrl === SerpFormat::OTHER ? 'no clear signal in the address or title' : 'address or title pattern']];
        }
        [$type, $signals] = $content;
        if ($root) {
            // A shop's home page shows prices and products too; the address decides.
            return ['type' => SerpFormat::HOME, 'confidence' => self::HIGH, 'signals' => ['home page address', ...$signals]];
        }
        if ($byUrl !== SerpFormat::OTHER && $byUrl !== $type) {
            $signals[] = 'the address suggests ' . $byUrl;

            return ['type' => $type, 'confidence' => self::MEDIUM, 'signals' => $signals];
        }

        return ['type' => $type, 'confidence' => count($signals) >= 2 ? self::HIGH : self::MEDIUM, 'signals' => $signals];
    }

    /**
     * @return array{0: string, 1: list<string>}|null
     */
    private static function fromContent(PageStructure $page): ?array
    {
        $schema = array_fill_keys($page->schemaTypes, true);
        $e = $page->elements;
        $title = mb_strtolower(($page->title ?? '') . ' ' . ($page->h1 ?? ''));
        $grid = (bool) ($e['grid'] ?? false);
        // A listing of products: collection schema, or a product grid without a single product's schema.
        if (isset($schema['CollectionPage']) || isset($schema['ItemList']) && (($e['price'] ?? false) || $grid) || $grid && !isset($schema['Product'])) {
            return [SerpFormat::CATEGORY, array_values(array_filter([$grid ? 'a product grid' : null, isset($schema['ItemList']) ? 'ItemList schema' : null, isset($schema['CollectionPage']) ? 'CollectionPage schema' : null]))];
        }
        $articleSchema = isset($schema['Article']) || isset($schema['BlogPosting']) || isset($schema['NewsArticle']);
        if (isset($schema['Product']) || ($e['price'] ?? false) && ($e['cart'] ?? false) && !$articleSchema) {
            return [SerpFormat::PRODUCT, array_values(array_filter([isset($schema['Product']) ? 'Product schema' : null, ($e['price'] ?? false) ? 'a price' : null, ($e['cart'] ?? false) ? 'an add-to-cart button' : null, ($e['variants'] ?? false) ? 'variant selectors' : null]))];
        }
        if (isset($schema['LocalBusiness']) || ($e['map'] ?? false)) {
            return [SerpFormat::LOCAL, [isset($schema['LocalBusiness']) ? 'LocalBusiness schema' : 'an embedded map']];
        }
        if (($e['form'] ?? false) && preg_match('/\b(calculator|checker|generator|converter|estimator|tool)\b/u', $title) === 1) {
            return [SerpFormat::TOOL, ['a form', 'a tool name in the title']];
        }
        if (isset($schema['Service'])) {
            return [SerpFormat::SERVICE, ['Service schema']];
        }
        $article = isset($schema['Article']) || isset($schema['BlogPosting']) || isset($schema['NewsArticle']) || isset($schema['HowTo']) || ($e['byline'] ?? false);
        if ($article) {
            $signals = array_values(array_filter([isset($schema['Article']) || isset($schema['BlogPosting']) || isset($schema['NewsArticle']) ? 'Article schema' : null, isset($schema['HowTo']) ? 'HowTo schema' : null, ($e['byline'] ?? false) ? 'an author or date byline' : null, ($e['toc'] ?? false) ? 'a table of contents' : null]));
            if (preg_match('/\b(best|top \d+|\d+ best|reviews?|vs\.?|versus|compared|comparison|alternatives?)\b/u', $title) === 1) {
                return [SerpFormat::REVIEW, [...$signals, 'a best-of or comparison title']];
            }

            return [SerpFormat::ARTICLE, $signals];
        }

        return null;
    }
}

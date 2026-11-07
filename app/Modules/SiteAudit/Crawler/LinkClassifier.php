<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

use SeoControlCenter\Domain\InternalLinks\LinkClass;

/**
 * Classifies one link from its place in the page (ADR-042, 0.35.0). Page
 * regions (nav, header, footer, aside) are templates; class and id names
 * mark related-article, product and navigation blocks; a link inside a
 * sentence of real text is editorial; a block that is little more than the
 * link (a heading or image card) is a card, not a paragraph link.
 */
final class LinkClassifier
{
    private const array TEMPLATE_HINTS = ['breadcrumb', 'breadcrumbs', 'pagination', 'pager', 'menu', 'navbar', 'nav', 'navigation', 'toc', 'table-of-contents', 'tabs', 'mega-menu', 'site-header', 'site-footer', 'sidebar', 'widget'];
    private const array PRODUCT_HINTS = ['product', 'products', 'woocommerce', 'price', 'add-to-cart', 'cart', 'upsell', 'upsells', 'cross-sell', 'cross-sells', 'crosssell', 'shop-item', 'product-card', 'related-products', 'buy'];
    private const array RELATED_HINTS = ['related', 'related-posts', 'related-articles', 'recommended', 'read-next', 'read-more', 'more-posts', 'post-card', 'article-card', 'blog-card', 'card', 'cards', 'teaser', 'you-may-also-like', 'also-like', 'similar', 'latest-posts', 'recent-posts', 'post-list', 'posts'];
    /** How far up the tree class names are read. */
    private const int DEPTH = 8;

    /** @return array{class: string, confidence: string, signal: string} */
    public static function classify(\Dom\Element $anchor, string $place, string $text): array
    {
        if ($place !== 'body') {
            return ['class' => LinkClass::TEMPLATE, 'confidence' => LinkClass::HIGH, 'signal' => 'page region: ' . $place];
        }
        $node = $anchor->parentElement;
        for ($depth = 0; $node !== null && $depth < self::DEPTH; $node = $node->parentElement, $depth++) {
            $tag = strtolower($node->tagName);
            if ($tag === 'body') {
                break;
            }
            $tokens = self::tokens($node);
            if (array_intersect($tokens, self::PRODUCT_HINTS) !== []) {
                return ['class' => LinkClass::PRODUCT, 'confidence' => LinkClass::HIGH, 'signal' => 'block named ' . implode(' ', array_slice(array_values(array_intersect($tokens, self::PRODUCT_HINTS)), 0, 2))];
            }
            if (array_intersect($tokens, self::RELATED_HINTS) !== []) {
                return ['class' => LinkClass::RELATED, 'confidence' => LinkClass::HIGH, 'signal' => 'block named ' . implode(' ', array_slice(array_values(array_intersect($tokens, self::RELATED_HINTS)), 0, 2))];
            }
            if (array_intersect($tokens, self::TEMPLATE_HINTS) !== []) {
                return ['class' => LinkClass::TEMPLATE, 'confidence' => LinkClass::MEDIUM, 'signal' => 'block named ' . implode(' ', array_slice(array_values(array_intersect($tokens, self::TEMPLATE_HINTS)), 0, 2))];
            }
            // The page's own article or main area: names above it describe the whole page, not this link.
            if (in_array($tag, ['article', 'main'], true) || strtolower((string) $node->getAttribute('role')) === 'main') {
                break;
            }
        }
        $block = $anchor->closest('p, li, td, dd, blockquote, figcaption');
        $blockText = self::clean($block?->textContent);
        $anchorLength = mb_strlen($text);
        // A link inside a sentence: the text around it is clearly longer than the link itself.
        if ($block !== null && $blockText !== '' && mb_strlen($blockText) >= $anchorLength + 40 && $anchorLength <= 0.7 * mb_strlen($blockText) && $anchor->querySelector('h1, h2, h3, h4, img') === null) {
            $inArticle = $anchor->closest('article, main, [role="main"]') !== null;
            $tag = strtolower($block->tagName);

            return ['class' => LinkClass::EDITORIAL, 'confidence' => $inArticle && $tag !== 'li' ? LinkClass::HIGH : LinkClass::MEDIUM, 'signal' => 'inside a ' . ($tag === 'li' ? 'list item' : 'paragraph') . ' of text'];
        }
        // A heading or image wrapped in a link, or a block that is little more than the link: a card.
        if ($anchor->querySelector('h2, h3, h4, img') !== null || ($anchor->parentElement !== null && preg_match('/^h[2-4]$/', strtolower($anchor->parentElement->tagName)) === 1)) {
            $price = preg_match('/(?:[$€£]\s?\d|\d[\d.,]*\s?(?:€|eur|usd|gbp)\b)/iu', self::clean($anchor->closest('li, article, div')?->textContent)) === 1;

            return $price
                ? ['class' => LinkClass::PRODUCT, 'confidence' => LinkClass::MEDIUM, 'signal' => 'card with a price']
                : ['class' => LinkClass::RELATED, 'confidence' => LinkClass::LOW, 'signal' => 'heading or image card'];
        }

        return ['class' => LinkClass::OTHER, 'confidence' => LinkClass::LOW, 'signal' => 'body link outside a sentence'];
    }

    /** @return list<string> lower-case class and id words, also split on dashes and underscores */
    private static function tokens(\Dom\Element $node): array
    {
        $raw = strtolower(trim((string) $node->getAttribute('class') . ' ' . (string) $node->getAttribute('id')));
        if ($raw === '') {
            return [];
        }
        $tokens = [];
        foreach (preg_split('/\s+/', $raw) ?: [] as $name) {
            $tokens[] = $name;
            foreach (preg_split('/[-_]+/', $name) ?: [] as $part) {
                $tokens[] = $part;
            }
        }

        return array_values(array_unique(array_filter($tokens, static fn(string $t): bool => $t !== '')));
    }

    private static function clean(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    }
}

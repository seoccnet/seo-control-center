<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\InternalLinks;

/**
 * What kind of link an internal link is (ADR-042, 0.35.0): a link in the
 * running text, a related-article card, a product or cross-sell card, a
 * repeated template link (menu, header, footer, sidebar, breadcrumbs), or
 * another link in the body that fits none of these. Each comes with a
 * confidence and the signal it was based on.
 */
final class LinkClass
{
    public const string EDITORIAL = 'editorial';
    public const string RELATED = 'related';
    public const string PRODUCT = 'product';
    public const string TEMPLATE = 'template';
    public const string OTHER = 'other';

    public const array LABELS = [
        self::EDITORIAL => 'In the text',
        self::RELATED => 'Related-article card',
        self::PRODUCT => 'Product or cross-sell card',
        self::TEMPLATE => 'Menu, footer or sidebar',
        self::OTHER => 'Other body link',
    ];

    public const string HIGH = 'high';
    public const string MEDIUM = 'medium';
    public const string LOW = 'low';

    /** Body links of an audit crawled before classes were recorded (0.35.1): not "other", just unknown. */
    public const string UNCLASSIFIED = 'unclassified';

    /** Classes that are not a link in the running text: a paragraph link can still be suggested. */
    public const array NOT_TEXT = [self::RELATED, self::PRODUCT, self::TEMPLATE];

    /**
     * Links of one page grouped by class. Template links into a page repeat
     * on every page that carries the template: they are counted once as one
     * template context, with the number of pages that carry it.
     *
     * @param list<array{url: string, anchor: ?string, place: ?string, class: ?string, confidence: ?string, context: ?string}> $links
     * @return array<string, array{label: string, count: int, pages: int, links: list<array{url: string, anchor: ?string, place: ?string, class: ?string, confidence: ?string, context: ?string}>}>
     */
    public static function summarize(array $links): array
    {
        $groups = [];
        foreach ($links as $link) {
            // Older audits have no class: a region link is still a template, a body link stays unclassified.
            $class = $link['class'] ?? ($link['place'] === null ? null : ($link['place'] === 'body' ? self::UNCLASSIFIED : self::TEMPLATE));
            $class = isset(self::LABELS[$class ?? '']) || $class === self::UNCLASSIFIED ? (string) $class : 'unknown';
            $groups[$class] ??= ['label' => self::LABELS[$class] ?? ($class === self::UNCLASSIFIED ? 'Not classified (crawled before 0.35.0)' : 'Not recorded'), 'count' => 0, 'pages' => 0, 'links' => [], 'contexts' => []];
            $groups[$class]['pages']++;
            // The same anchor in the same template region is one context, however many pages repeat it.
            $context = $class === self::TEMPLATE ? ($link['place'] ?? '') . '|' . mb_strtolower(trim((string) $link['anchor'])) : $link['url'];
            if (!isset($groups[$class]['contexts'][$context])) {
                $groups[$class]['contexts'][$context] = true;
                $groups[$class]['count']++;
                if (count($groups[$class]['links']) < ($class === self::TEMPLATE ? 5 : 200)) {
                    $groups[$class]['links'][] = $link;
                }
            }
        }
        $order = array_flip([self::EDITORIAL, self::RELATED, self::PRODUCT, self::OTHER, self::UNCLASSIFIED, self::TEMPLATE, 'unknown']);
        uksort($groups, static fn(string $a, string $b): int => $order[$a] <=> $order[$b]);

        return array_map(static fn(array $g): array => ['label' => $g['label'], 'count' => $g['count'], 'pages' => $g['pages'], 'links' => $g['links']], $groups);
    }
}

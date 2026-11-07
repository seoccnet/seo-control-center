<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

/**
 * Reads the structure of an HTML page (ADR-038): H1–H3, schema types, word
 * and media counts, links, dates and commerce or article elements. The HTML
 * is untrusted; it is parsed without network access and never rendered,
 * and no body text is kept. Pages that look like a bot challenge or are
 * nearly empty count as blocked.
 *
 * Headings come from the page's main content: site chrome (page header and
 * footer, navigation, asides, dialogs, hidden blocks) and boilerplate blocks
 * (cookie, privacy, newsletter, modal, sidebar, comment, related-post
 * containers) are skipped, and when an <article> or <main> holds the page's
 * headings only its headings count.
 */
final class OutlineExtractor
{
    public const int MAX_HEADINGS = 60;
    public const int MAX_QUESTIONS = 20;
    private const int MAX_TEXT = 160;
    /** class or id words of containers whose headings are not the page's own content */
    private const array BOILERPLATE = ['cookie', 'consent', 'gdpr', 'privacy', 'newsletter', 'subscribe', 'modal', 'popup', 'dialog', 'offcanvas', 'sidebar', 'footer', 'menu', 'breadcrumb', 'comments', 'related-posts'];

    public static function extract(string $html, string $url): PageStructure
    {
        if (trim($html) === '') {
            return PageStructure::unread($url, PageStructure::BLOCKED, 'The page returned no content.');
        }
        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return PageStructure::unread($url, PageStructure::FAILED, 'The page could not be parsed.');
        }
        $xpath = new \DOMXPath($doc);
        $nodes = static function (string $query) use ($xpath): array {
            $list = $xpath->query($query);
            $result = [];
            foreach ($list === false ? [] : $list as $node) {
                if ($node instanceof \DOMNode) {
                    $result[] = $node;
                }
            }

            return $result;
        };
        $first = static fn(string $query): ?\DOMNode => $nodes($query)[0] ?? null;
        $count = static fn(string $query): int => count($nodes($query));
        $text = static fn(?\DOMNode $n): string => $n === null ? '' : mb_substr(trim((string) preg_replace('/\s+/u', ' ', $n->textContent)), 0, self::MAX_TEXT);

        // JSON-LD before scripts are removed.
        $schema = [];
        $dates = ['published' => null, 'modified' => null];
        foreach ($nodes('//script[@type="application/ld+json"]') as $node) {
            $data = json_decode($node->textContent, true);
            if (is_array($data)) {
                self::schema($data, $schema, $dates);
            }
        }
        foreach ($nodes('//*[@itemtype]') as $node) {
            if ($node instanceof \DOMElement) {
                $type = basename(trim($node->getAttribute('itemtype')));
                if ($type !== '') {
                    $schema[$type] = true;
                }
            }
        }
        foreach (['article:published_time' => 'published', 'article:modified_time' => 'modified', 'og:updated_time' => 'modified'] as $property => $key) {
            $meta = $first('//meta[@property="' . $property . '"]/@content');
            if ($dates[$key] === null && $meta !== null) {
                $dates[$key] = self::date($meta->nodeValue);
            }
        }
        foreach ($nodes('//script|//style|//noscript|//template|//svg') as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $first('//body');
        $bodyText = $body === null ? '' : $body->textContent;
        $words = count(preg_split('/\s+/u', trim($bodyText), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        $title = $text($first('//title'));
        $challenge = preg_match('/\b(just a moment|attention required|access denied|verify you are (a )?human|enable javascript|checking your browser|captcha)\b/iu', $title . ' ' . mb_substr($bodyText, 0, 600)) === 1;
        if ($words < 30 || ($challenge && $words < 400)) {
            return PageStructure::unread($url, PageStructure::BLOCKED, 'The site showed a challenge or an almost empty page.');
        }

        $outline = [];
        $questions = [];
        foreach (self::contentHeadings($xpath, $nodes) as $node) {
            $heading = $text($node);
            if ($heading === '' || count($outline) >= self::MAX_HEADINGS) {
                continue;
            }
            $outline[] = ['level' => $node->nodeName === 'h2' ? 2 : 3, 'text' => $heading];
            if (count($questions) < self::MAX_QUESTIONS && (str_ends_with($heading, '?') || preg_match('/^(how|what|why|when|where|which|who|can|does|do|is|are|should)\b/iu', $heading) === 1)) {
                $questions[] = $heading;
            }
        }
        $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        $internal = $outbound = 0;
        foreach ($nodes('//a[@href]') as $a) {
            if (!$a instanceof \DOMElement) {
                continue;
            }
            $href = trim($a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || preg_match('/^(mailto|tel|javascript):/i', $href) === 1) {
                continue;
            }
            $linkHost = (string) preg_replace('/^www\./', '', strtolower((string) (parse_url($href, PHP_URL_HOST) ?? '')));
            if ($linkHost === '' || $linkHost === $host) {
                ++$internal;
            } else {
                ++$outbound;
            }
        }
        $lower = strtolower($html);
        $types = array_map('strval', array_keys($schema));
        sort($types);
        $elements = [
            'price' => isset($schema['Offer']) || isset($schema['AggregateOffer']) || $count('//*[@itemprop="price"]') > 0 || preg_match('/class="[^"]*\bprice\b/', $lower) === 1,
            'cart' => preg_match('/(add[ -]to[ -](cart|basket|bag)|in den warenkorb|buy now)/', $lower) === 1,
            'variants' => $count('//select[contains(@name,"option") or contains(@name,"variant") or contains(@name,"size")]') > 0,
            // A listing: product cards, or many prices or add-to-cart buttons on one page.
            'grid' => $count('//*[contains(@class,"product-card") or contains(@class,"product-item") or contains(@class,"products-grid") or contains(@class,"product-grid") or contains(concat(" ", normalize-space(@class), " ")," product ")]') >= 4
                || preg_match_all('/add[ -]to[ -](cart|basket|bag)/', $lower) >= 4
                || $count('//*[@itemprop="price" or contains(concat(" ", normalize-space(@class), " ")," price ")]') >= 6,
            'byline' => $count('//*[@rel="author" or contains(@class,"author") or contains(@class,"byline")]') > 0 || $dates['published'] !== null,
            'toc' => $count('//*[contains(@class,"toc") or @id="toc" or contains(@class,"table-of-contents")]') > 0,
            'form' => $count('//form[.//input[@type="number" or @type="text"] and not(.//input[@type="search"]) and not(.//input[@type="email"])]') > 0,
            'map' => $count('//iframe[contains(@src,"google.com/maps")]') > 0 || isset($schema['LocalBusiness']),
        ];
        $description = trim((string) $first('//meta[@name="description"]/@content')?->nodeValue);
        $h1 = $text($first('//h1'));

        return new PageStructure(
            $url,
            PageStructure::OK,
            null,
            $title !== '' ? $title : null,
            $description !== '' ? mb_substr($description, 0, 300) : null,
            $h1 !== '' ? $h1 : null,
            $outline,
            $questions,
            array_slice($types, 0, 20),
            $words,
            $count('//img'),
            $count('//video') + $count('//iframe[contains(@src,"youtube") or contains(@src,"vimeo")]'),
            $count('//table'),
            $count('//ul|//ol'),
            $internal,
            $outbound,
            $dates['published'],
            $dates['modified'],
            $elements,
            hash('sha256', json_encode($outline, JSON_THROW_ON_ERROR)),
        );
    }

    /**
     * The H2/H3 headings of the page's own content (see the class comment).
     *
     * @param \Closure(string): list<\DOMNode> $nodes
     * @return list<\DOMNode>
     */
    private static function contentHeadings(\DOMXPath $xpath, \Closure $nodes): array
    {
        $words = [];
        foreach (self::BOILERPLATE as $word) {
            $words[] = sprintf('contains(concat(" ", translate(@class, "ABCDEFGHIJKLMNOPQRSTUVWXYZ_", "abcdefghijklmnopqrstuvwxyz-"), " ", translate(@id, "ABCDEFGHIJKLMNOPQRSTUVWXYZ_", "abcdefghijklmnopqrstuvwxyz-"), " "), "%s")', $word);
        }
        $chrome = 'self::nav or self::aside or self::dialog or self::form'
            . ' or ((self::header or self::footer) and not(ancestor::article) and not(ancestor::main))'
            . ' or @hidden or @aria-hidden="true" or @aria-modal="true"'
            . ' or @role="dialog" or @role="alertdialog" or @role="navigation" or @role="banner" or @role="contentinfo" or @role="complementary"'
            // A boilerplate class only on containers that do not hold the main content (themes put "no-sidebar" on page wrappers).
            . ' or ((' . implode(' or ', $words) . ') and not(self::body) and not(self::html) and not(self::main) and not(self::article) and not(.//main) and not(.//article))';
        $own = static fn(\DOMNode $heading): bool => $xpath->evaluate('count(ancestor-or-self::*[' . $chrome . '])', $heading) == 0;
        $all = $nodes('//h2|//h3');
        $headings = array_values(array_filter($all, $own));
        if ($headings === []) {
            return $all;
        }
        // The main content, when one container holds most of the page's own headings.
        $best = null;
        $bestCount = 0;
        foreach ($nodes('//article|//main|//*[@role="main"]') as $container) {
            $inside = array_values(array_filter($headings, static fn(\DOMNode $h): bool => $container->isSameNode($h) || self::contains($container, $h)));
            if (count($inside) > $bestCount) {
                [$best, $bestCount] = [$inside, count($inside)];
            }
        }

        return $best !== null && $bestCount >= 2 ? $best : $headings;
    }

    private static function contains(\DOMNode $ancestor, \DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if ($parent->isSameNode($ancestor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, true> $types
     * @param array{published: ?string, modified: ?string} $dates
     */
    private static function schema(array $data, array &$types, array &$dates, int $depth = 0): void
    {
        if ($depth > 6) {
            return;
        }
        foreach ((array) ($data['@type'] ?? []) as $type) {
            if (is_string($type) && preg_match('/^[A-Za-z]{2,40}$/', $type) === 1) {
                $types[$type] = true;
            }
        }
        foreach (['datePublished' => 'published', 'dateModified' => 'modified'] as $key => $target) {
            if ($dates[$target] === null && is_string($data[$key] ?? null)) {
                $dates[$target] = self::date($data[$key]);
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                self::schema($value, $types, $dates, $depth + 1);
            }
        }
    }

    private static function date(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d{4}-\d{2}-\d{2})/', trim($value), $m) !== 1) {
            return null;
        }

        return checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4)) ? $m[1] : null;
    }
}

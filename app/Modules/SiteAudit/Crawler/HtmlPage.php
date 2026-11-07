<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

/**
 * What the audit reads from an HTML page.
 */
final readonly class HtmlPage
{
    public const int MAX_LINKS = 500;
    /** Body paragraphs kept per page for link suggestions (ADR-040, F1). */
    public const int MAX_PARAGRAPHS = 25;
    public const int PARAGRAPH_LENGTH = 400;
    public const int ANCHOR_LENGTH = 160;
    /** Where a link sits: page regions other than the body text. */
    private const array REGIONS = ['nav' => 'nav', 'header' => 'header', 'footer' => 'footer', 'aside' => 'aside'];
    private const array ROLES = ['navigation' => 'nav', 'banner' => 'header', 'contentinfo' => 'footer', 'complementary' => 'aside'];

    /**
     * @param list<string> $links absolute, normalised http(s) URLs, unique, at most MAX_LINKS
     * @param list<array{url: string, kind: string}> $resources
     * @param list<string> $h1s First 20 H1 texts, each bounded to 500 characters.
     * @param list<string> $schemaTypes structured data types (JSON-LD and microdata), at most 20, e.g. "Product"
     * @param array<string, array{anchor: string, place: string, context: ?string, class: string, confidence: string, signal: string}> $linkContexts per linked URL: its first
     *        occurrence, preferring a link in the text, then other body links, then templates; context is the surrounding
     *        paragraph of a body link; class and confidence say what kind of link it is (LinkClass, 0.35.0)
     * @param list<string> $paragraphs up to MAX_PARAGRAPHS body paragraphs (outside navigation, header, footer and asides)
     * @param int $sourceLinks other sites linked from the text itself (editorial body links), counted once per site (0.42.0)
     * @param array{questions: int, tables: int, lists: int, figures: int, lead_words: ?int, updated: bool}|array{} $citation what the page
     *        offers AI answers to quote (0.43.0); empty when not read
     * @param array{types: list<string>, name: ?string, phones: list<string>, address: ?string}|array{} $business the business
     *        details the page states (0.46.0): the first structured-data node with an address or telephone (its types,
     *        name and address) and every phone number in that markup or in tel: links, as digits; empty when not read
     */
    public function __construct(
        public ?string $title,
        public ?string $description,
        public int $h1Count,
        public ?string $h1,
        public ?string $canonical,
        public bool $noindex,
        public bool $nofollow,
        public int $wordCount,
        public array $links,
        public array $resources = [],
        public array $h1s = [],
        public array $schemaTypes = [],
        public ?string $ogType = null,
        public bool $hasAuthor = false,
        public array $linkContexts = [],
        public array $paragraphs = [],
        public int $sourceLinks = 0,
        public array $citation = [],
        public array $business = [],
    ) {}

    /**
     * Parses an HTML document; $url is the page's own address, for relative links.
     */
    public static function parse(string $html, string $url): self
    {
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR | LIBXML_COMPACT);

        $base = $url;
        $baseHref = $document->querySelector('base[href]')?->getAttribute('href');
        if (is_string($baseHref)) {
            $base = UrlNormalizer::resolve($baseHref, $url) ?? $url;
        }

        $title = self::clean($document->querySelector('title')?->textContent);
        $description = null;
        $noindex = false;
        $nofollow = false;
        foreach ($document->querySelectorAll('meta[name]') as $meta) {
            $name = strtolower(trim((string) $meta->getAttribute('name')));
            $content = (string) $meta->getAttribute('content');
            if ($name === 'description' && $description === null) {
                $description = self::clean($content);
            } elseif ($name === 'robots' || $name === 'googlebot') {
                $directives = self::directives($content);
                $noindex = $noindex || in_array('noindex', $directives, true) || in_array('none', $directives, true);
                $nofollow = $nofollow || in_array('nofollow', $directives, true) || in_array('none', $directives, true);
            }
        }

        $canonical = null;
        foreach ($document->querySelectorAll('link[rel][href]') as $link) {
            if (in_array('canonical', self::directives((string) $link->getAttribute('rel'), ' '), true)) {
                $canonical = UrlNormalizer::resolve((string) $link->getAttribute('href'), $base);
                break;
            }
        }

        $headings = $document->querySelectorAll('h1');
        $h1 = $headings->length > 0 ? self::clean($headings->item(0)?->textContent) : null;
        $h1s = [];
        for ($i = 0; $i < min(20, $headings->length); ++$i) {
            $h1s[] = mb_substr(self::clean($headings->item($i)?->textContent) ?? '', 0, 500);
        }

        $links = [];
        $contexts = [];
        $sources = [];
        $ownHost = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST)));
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            $target = UrlNormalizer::resolve((string) $anchor->getAttribute('href'), $base);
            if ($target !== null) {
                $links[$target] = true;
                // A link in the text wins over a card, and a card over a menu or footer link to the same page.
                $place = self::place($anchor);
                $text = self::clean($anchor->textContent) ?? self::clean($anchor->querySelector('img[alt]')?->getAttribute('alt')) ?? '';
                $kind = LinkClassifier::classify($anchor, $place, $text);
                // A link to another site from the text itself is a cited source; menus, cards and footers are not.
                $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($target, PHP_URL_HOST)));
                if ($place === 'body' && $kind['class'] === 'editorial' && $host !== '' && $host !== $ownHost) {
                    $sources[$host] = true;
                }
                if (!isset($contexts[$target]) || self::rank($kind['class']) < self::rank($contexts[$target]['class'])) {
                    $contexts[$target] = ['anchor' => mb_substr($text, 0, self::ANCHOR_LENGTH), 'place' => $place, 'context' => $place === 'body' ? self::context($anchor, $text) : null, ...$kind];
                }
                if (count($links) >= self::MAX_LINKS) {
                    break;
                }
            }
        }
        $paragraphs = self::paragraphs($document);

        $resources = [];
        foreach ($document->querySelectorAll('img[src], script[src], link[rel][href]') as $element) {
            $kind = match (strtolower($element->tagName)) {
                'img' => $element->hasAttribute('alt') ? 'image' : 'image_alt',
                'script' => 'script',
                default => in_array('stylesheet', self::directives((string) $element->getAttribute('rel'), ' '), true) ? 'stylesheet' : null,
            };
            if ($kind === null) {
                continue;
            }
            $target = UrlNormalizer::resolve((string) $element->getAttribute($kind === 'stylesheet' ? 'href' : 'src'), $base);
            if ($target !== null) {
                $resources[$kind . ':' . $target] = ['url' => $target, 'kind' => $kind];
            }
            if (count($resources) >= 15) {
                break;
            }
        }
        [$schemaTypes, $schemaAuthor, $schemaModified, $business] = self::structuredData($document);
        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            if (str_starts_with(strtolower($href), 'tel:')) {
                $business['phones'][] = self::phone(rawurldecode(substr($href, 4)));
            }
        }
        $business['phones'] = array_slice(array_values(array_unique(array_filter($business['phones'], static fn(string $p): bool => strlen(ltrim($p, '+')) >= 6))), 0, 5);
        $citation = self::citation($document, $schemaModified);
        $ogType = null;
        foreach ($document->querySelectorAll('meta[property]') as $meta) {
            if (strtolower(trim((string) $meta->getAttribute('property'))) === 'og:type') {
                $og = strtolower(trim((string) $meta->getAttribute('content')));
                $ogType = preg_match('/^[a-z0-9._:\-]{1,40}$/', $og) === 1 ? $og : null;
                break;
            }
        }
        $author = $schemaAuthor;
        foreach ($document->querySelectorAll('meta[name]') as $meta) {
            if (strtolower(trim((string) $meta->getAttribute('name'))) === 'author' && trim((string) $meta->getAttribute('content')) !== '') {
                $author = true;
            }
        }
        $author = $author || $document->querySelector('[rel~="author"], [itemprop~="author"], [class*="author"], [class*="byline"]') !== null;

        $wordCount = 0;
        $body = $document->body;
        if ($body !== null) {
            foreach ($body->querySelectorAll('script, style, noscript, template, svg') as $hidden) {
                $hidden->remove();
            }
            $wordCount = (int) preg_match_all('/[\p{L}\p{N}]+/u', mb_substr($body->textContent ?? '', 0, 1_000_000));
        }

        return new self(
            $title !== null ? mb_substr($title, 0, 500) : null,
            $description !== null ? mb_substr($description, 0, 1000) : null,
            $headings->length,
            $h1 !== null ? mb_substr($h1, 0, 500) : null,
            $canonical,
            $noindex,
            $nofollow,
            $wordCount,
            array_keys($links),
            array_values($resources),
            $h1s,
            $schemaTypes,
            $ogType,
            $author,
            $contexts,
            $paragraphs,
            count($sources),
            $citation,
            $business,
        );
    }

    /** A phone number as digits, with a leading + kept. */
    public static function phone(string $value): string
    {
        $value = trim($value);

        return (str_starts_with($value, '+') ? '+' : '') . (string) preg_replace('/\D+/', '', $value);
    }

    /**
     * What the page offers AI answers to quote (0.43.0), read from the body
     * text outside navigation, header, footer and asides: question headings
     * (H2 to H4 and accordion titles), data tables (two rows or more, or a
     * list of terms with three entries or more), lists of three or more
     * written items, paragraphs with figures, the word count of the opening
     * paragraph, and whether a modified date is stated (structured data or
     * meta tags).
     *
     * @return array{questions: int, tables: int, lists: int, figures: int, lead_words: ?int, updated: bool}
     */
    private static function citation(\Dom\HTMLDocument $document, bool $modified): array
    {
        $body = $document->body;
        $questions = $tables = $lists = $figures = 0;
        // Accordion titles (<summary>) and H4s count too: FAQ blocks often use them (0.44.0).
        foreach ($body?->querySelectorAll('h2, h3, h4, summary') ?? [] as $heading) {
            $text = self::clean($heading->textContent);
            if ($text !== null && self::place($heading) === 'body' && self::isQuestion($text)) {
                ++$questions;
            }
        }
        foreach ($body?->querySelectorAll('table') ?? [] as $table) {
            if (self::place($table) === 'body' && $table->querySelectorAll('tr')->length >= 2) {
                ++$tables;
            }
        }
        // A list of terms with three entries or more (NPK, flowering time, breeder …) is a specification table too (0.44.0).
        foreach ($body?->querySelectorAll('dl') ?? [] as $terms) {
            if (self::place($terms) === 'body' && $terms->querySelectorAll('dt')->length >= 3) {
                ++$tables;
            }
        }
        foreach ($body?->querySelectorAll('ul, ol') ?? [] as $list) {
            if (self::place($list) !== 'body') {
                continue;
            }
            // Written items, not a list of links (menus and product grids outside a nav element).
            $written = 0;
            foreach ($list->querySelectorAll('li') as $item) {
                $text = self::clean($item->textContent) ?? '';
                $linked = '';
                foreach ($item->querySelectorAll('a') as $anchor) {
                    $linked .= $anchor->textContent;
                }
                if (mb_strlen($text) >= 20 && mb_strlen($text) > 2 * mb_strlen(self::clean($linked) ?? '')) {
                    ++$written;
                }
            }
            if ($written >= 3) {
                ++$lists;
            }
        }
        $lead = null;
        $main = $body?->querySelector('main, article, [role="main"]') ?? $body;
        foreach ($main?->querySelectorAll('p') ?? [] as $paragraph) {
            if (self::place($paragraph) !== 'body') {
                continue;
            }
            $text = self::clean($paragraph->textContent) ?? '';
            $words = (int) preg_match_all('/[\p{L}\p{N}]+/u', $text);
            if ($lead === null && $words >= 8) {
                $lead = $words;
            }
            // Figures: a percentage, a large or scaled number, or two numbers or more (a lone year is not a fact).
            if (preg_match('/\d+(?:[.,]\d+)?\s?(?:%|percent\b|per cent\b)|\b\d{1,3}(?:[.,]\d{3})+\b|\b\d+(?:[.,]\d+)?\s?(?:times|million|billion|thousand)\b|\b\d+\s?x\b/iu', $text) === 1
                || preg_match_all('/(?<![\p{L}\d])\d+(?:[.,]\d+)?(?![\p{L}\d])/u', $text) >= 2) {
                ++$figures;
            }
        }
        foreach ($document->querySelectorAll('meta[property], [itemprop~="dateModified"]') as $element) {
            $property = strtolower(trim((string) $element->getAttribute('property')));
            if ((in_array($property, ['article:modified_time', 'og:updated_time'], true) && trim((string) $element->getAttribute('content')) !== '') || $element->hasAttribute('itemprop')) {
                $modified = true;
            }
        }

        return ['questions' => min(255, $questions), 'tables' => min(255, $tables), 'lists' => min(255, $lists), 'figures' => min(255, $figures), 'lead_words' => $lead !== null ? min(9999, $lead) : null, 'updated' => $modified];
    }

    /** A heading phrased as a question: ending in "?" or opening with an English question word. */
    private static function isQuestion(string $heading): bool
    {
        return str_ends_with($heading, '?')
            || preg_match('/^(what|how|why|when|where|which|who|whose|can|could|does|do|did|is|are|was|should|will|would)\b/i', $heading) === 1;
    }

    /** Which occurrence of a link to the same page is kept: text first, templates last. */
    private static function rank(string $class): int
    {
        return match ($class) {
            \SeoControlCenter\Domain\InternalLinks\LinkClass::EDITORIAL => 0,
            \SeoControlCenter\Domain\InternalLinks\LinkClass::TEMPLATE => 2,
            default => 1,
        };
    }

    /** body, nav, header, footer or aside: the closest page region around an element. */
    private static function place(\Dom\Element $element): string
    {
        for ($node = $element->parentElement; $node !== null; $node = $node->parentElement) {
            $tag = strtolower($node->tagName);
            if (isset(self::REGIONS[$tag])) {
                return self::REGIONS[$tag];
            }
            $role = strtolower(trim((string) $node->getAttribute('role')));
            if (isset(self::ROLES[$role])) {
                return self::ROLES[$role];
            }
        }

        return 'body';
    }

    /** The text block around a body link, centred on the anchor and cut to PARAGRAPH_LENGTH. */
    private static function context(\Dom\Element $anchor, string $text): ?string
    {
        $block = $anchor->closest('p, li, td, dd, blockquote, figcaption, h2, h3, h4') ?? $anchor->parentElement;
        $content = self::clean($block?->textContent);
        if ($content === null) {
            return null;
        }
        if (mb_strlen($content) <= self::PARAGRAPH_LENGTH) {
            return $content;
        }
        $at = $text !== '' ? mb_strpos($content, $text) : false;
        $start = $at === false ? 0 : max(0, min($at - intdiv(self::PARAGRAPH_LENGTH - mb_strlen($text), 2), mb_strlen($content) - self::PARAGRAPH_LENGTH));

        return ($start > 0 ? '…' : '') . trim(mb_substr($content, $start, self::PARAGRAPH_LENGTH - 2)) . '…';
    }

    /**
     * Body paragraphs and list items of at least 60 characters, outside page regions, without repeats.
     *
     * @return list<string>
     */
    private static function paragraphs(\Dom\HTMLDocument $document): array
    {
        $out = [];
        foreach ($document->body?->querySelectorAll('p, li') ?? [] as $element) {
            if (self::place($element) !== 'body' || $element->querySelector('p, li') !== null) {
                continue;
            }
            $text = self::clean($element->textContent);
            if ($text === null || mb_strlen($text) < 60) {
                continue;
            }
            $out[$text] = mb_substr($text, 0, self::PARAGRAPH_LENGTH);
            if (count($out) >= self::MAX_PARAGRAPHS) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * Structured data types from JSON-LD (including @graph) and microdata,
     * plus whether any of it names an author and states a modified date.
     *
     * @return array{list<string>, bool, bool, array{types: list<string>, name: ?string, phones: list<string>, address: ?string}}
     */
    private static function structuredData(\Dom\HTMLDocument $document): array
    {
        $types = [];
        $author = false;
        $modified = false;
        $business = ['types' => [], 'name' => null, 'phones' => [], 'address' => null];
        $visit = static function (mixed $node, int $depth) use (&$visit, &$types, &$author, &$modified, &$business): void {
            if (!is_array($node) || $depth > 6) {
                return;
            }
            foreach (isset($node['@type']) ? (array) $node['@type'] : [] as $type) {
                $name = is_string($type) ? (string) preg_replace('#^https?://schema\.org/#i', '', $type) : '';
                if (preg_match('/^[A-Za-z][A-Za-z0-9]{0,59}$/', $name) === 1) {
                    $types[$name] = true;
                }
            }
            if (array_key_exists('author', $node) && $node['author'] !== null && $node['author'] !== '' && $node['author'] !== []) {
                $author = true;
            }
            if (is_string($node['dateModified'] ?? null) && trim($node['dateModified']) !== '') {
                $modified = true;
            }
            // The business the page describes (0.46.0): the first node with an address or telephone.
            $nodeTypes = array_values(array_filter(array_map(static fn(mixed $t): string => is_string($t) ? (string) preg_replace('#^https?://schema\.org/#i', '', $t) : '', isset($node['@type']) ? (array) $node['@type'] : []), static fn(string $t): bool => $t !== '' && !in_array($t, ['Person', 'PostalAddress', 'ContactPoint'], true)));
            if ($nodeTypes !== [] && (isset($node['address']) || isset($node['telephone']))) {
                foreach ((array) ($node['telephone'] ?? []) as $phone) {
                    if (is_string($phone)) {
                        $business['phones'][] = self::phone($phone);
                    }
                }
                if ($business['types'] === []) {
                    $business['types'] = array_slice($nodeTypes, 0, 5);
                    $business['name'] = is_string($node['name'] ?? null) ? mb_substr(trim($node['name']), 0, 200) : null;
                    $address = $node['address'] ?? null;
                    if (is_array($address) && !array_is_list($address)) {
                        $address = implode(', ', array_filter(array_map(static fn(mixed $v): string => is_string($v) || is_int($v) ? trim((string) $v) : '', [$address['streetAddress'] ?? null, $address['postalCode'] ?? null, $address['addressLocality'] ?? null]), static fn(string $v): bool => $v !== ''));
                    }
                    $business['address'] = is_string($address) && trim($address) !== '' ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $address)), 0, 300) : null;
                }
            }
            foreach ($node as $key => $child) {
                if (is_array($child) && ($key === '@graph' || array_is_list($node) || in_array($key, ['mainEntity', 'itemListElement', 'offers', 'review'], true))) {
                    $visit($child, $depth + 1);
                }
            }
        };
        foreach ($document->querySelectorAll('script[type]') as $script) {
            if (strtolower(trim((string) $script->getAttribute('type'))) !== 'application/ld+json') {
                continue;
            }
            $json = (string) $script->textContent;
            if (strlen($json) > 200_000) {
                continue;
            }
            $visit(json_decode($json, true), 0);
        }
        foreach ($document->querySelectorAll('[itemtype]') as $element) {
            foreach (preg_split('/\s+/', trim((string) $element->getAttribute('itemtype'))) ?: [] as $itemType) {
                $name = (string) preg_replace('#^https?://schema\.org/#i', '', $itemType);
                if (preg_match('/^[A-Za-z][A-Za-z0-9]{0,59}$/', $name) === 1) {
                    $types[$name] = true;
                }
            }
        }

        return [array_slice(array_keys($types), 0, 20), $author, $modified, $business];
    }

    /**
     * Directives of a robots meta tag or X-Robots-Tag header, lower case.
     *
     * @return list<string>
     */
    public static function directives(string $value, string $separators = ', '): array
    {
        $parts = preg_split('/[' . preg_quote($separators, '/') . ']+/', strtolower($value)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn(string $p): bool => $p !== ''));
    }

    private static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text !== '' ? $text : null;
    }
}

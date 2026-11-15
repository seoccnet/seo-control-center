<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

/**
 * Validates a page's JSON-LD (1.2.0) against the properties Google requires
 * (invalid: no rich result) or recommends (incomplete) for the rich result
 * types it documents, and compares a product's name and price with the page
 * text (mismatch). Microdata is recognised elsewhere but not validated here.
 *
 * The page text is the server HTML only: a name or price that JavaScript
 * writes in later is reported as a mismatch, so the advice asks to verify.
 */
final class StructuredDataCheck
{
    public const int MAX_PROBLEMS = 10;
    public const string INVALID = 'invalid';
    public const string INCOMPLETE = 'incomplete';
    public const string MISMATCH = 'mismatch';

    private const array ARTICLES = ['Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report'];
    private const array PRODUCTS = ['Product', 'ProductGroup', 'IndividualProduct', 'ProductModel'];
    private const array VIDEOS = ['VideoObject'];
    private const array EVENTS = ['Event', 'MusicEvent', 'BusinessEvent', 'EducationEvent', 'Festival', 'SportsEvent', 'TheaterEvent', 'ExhibitionEvent', 'ComedyEvent', 'DanceEvent', 'FoodEvent', 'LiteraryEvent', 'SocialEvent'];

    /**
     * @param list<array{json: mixed}|array{error: string}> $blocks each JSON-LD script, decoded, or why it could not be
     * @param string $text the page's title, headings and body text, for the mismatch check
     * @return list<array{level: string, type: string, problem: string}>
     */
    public static function check(array $blocks, string $text): array
    {
        $problems = [];
        $add = static function (string $level, string $type, string $problem) use (&$problems): void {
            $key = $level . '|' . $type . '|' . $problem;
            $problems[$key] = ['level' => $level, 'type' => mb_substr($type, 0, 60), 'problem' => mb_substr($problem, 0, 200)];
        };
        $haystack = self::normalise($text);
        $numbers = null;
        foreach ($blocks as $block) {
            if (isset($block['error'])) {
                $add(self::INVALID, 'JSON-LD', $block['error']);
                continue;
            }
            $json = $block['json'];
            if (!is_array($json)) {
                $add(self::INVALID, 'JSON-LD', 'The script holds no object');
                continue;
            }
            $roots = array_is_list($json) ? $json : [$json];
            foreach ($roots as $root) {
                if (!is_array($root)) {
                    continue;
                }
                $context = $root['@context'] ?? null;
                if (!self::schemaContext($context)) {
                    $add(self::INVALID, self::typeName($root) ?? 'JSON-LD', $context === null ? 'No @context, so search engines do not read it as schema.org' : '@context is not schema.org');
                    continue;
                }
                $nodes = isset($root['@graph']) && is_array($root['@graph']) ? (array_is_list($root['@graph']) ? $root['@graph'] : [$root['@graph']]) : [$root];
                foreach ($nodes as $node) {
                    if (is_array($node)) {
                        self::node($node, 0, $add, $haystack, $numbers, $text);
                    }
                }
            }
        }

        return array_slice(array_values($problems), 0, self::MAX_PROBLEMS);
    }

    /**
     * @param array<mixed> $node
     * @param \Closure(string, string, string): void $add
     * @param list<float>|null $numbers
     */
    private static function node(array $node, int $depth, \Closure $add, string $haystack, ?array &$numbers, string $text): void
    {
        if ($depth > 5) {
            return;
        }
        $types = self::types($node);
        $type = $types[0] ?? null;
        $is = static fn(array $list): bool => array_intersect($types, $list) !== [];

        if ($type !== null && $is(self::PRODUCTS)) {
            if (!self::has($node, 'name')) {
                $add(self::INVALID, $type, 'Missing name');
            }
            if (!self::has($node, 'offers') && !self::has($node, 'review') && !self::has($node, 'aggregateRating')) {
                $add(self::INVALID, $type, 'Needs offers, review or aggregateRating');
            }
            if (!self::has($node, 'image')) {
                $add(self::INCOMPLETE, $type, 'No image');
            }
            $name = is_string($node['name'] ?? null) ? self::normalise($node['name']) : '';
            if ($name !== '' && $haystack !== '' && !str_contains($haystack, $name)) {
                $add(self::MISMATCH, $type, sprintf('Name "%s" is not in the page text', mb_substr(trim((string) $node['name']), 0, 80)));
            }
            foreach (self::children($node['offers'] ?? null) as $offer) {
                self::offer($offer, $type, $add, $haystack, $numbers, $text);
            }
        } elseif ($type !== null && in_array($type, ['Offer', 'AggregateOffer'], true) && $depth === 0) {
            self::offer($node, $type, $add, $haystack, $numbers, $text);
        }
        if ($is(['AggregateRating'])) {
            if (!self::has($node, 'ratingValue')) {
                $add(self::INVALID, 'AggregateRating', 'Missing ratingValue');
            }
            if (!self::has($node, 'ratingCount') && !self::has($node, 'reviewCount')) {
                $add(self::INVALID, 'AggregateRating', 'Needs ratingCount or reviewCount');
            }
        }
        if ($is(['Review'])) {
            if (!self::has($node, 'author')) {
                $add(self::INVALID, 'Review', 'Missing author');
            }
            $rating = $node['reviewRating'] ?? null;
            if (!is_array($rating) || !self::has($rating, 'ratingValue')) {
                $add(self::INVALID, 'Review', 'Missing reviewRating with ratingValue');
            }
        }
        if ($type !== null && $is(self::ARTICLES)) {
            foreach (['headline' => 'No headline', 'image' => 'No image', 'datePublished' => 'No datePublished', 'author' => 'No author'] as $property => $problem) {
                if (!self::has($node, $property)) {
                    $add(self::INCOMPLETE, $type, $problem);
                }
            }
        }
        if ($is(['BreadcrumbList'])) {
            $items = self::children($node['itemListElement'] ?? null);
            if ($items === []) {
                $add(self::INVALID, 'BreadcrumbList', 'No itemListElement');
            }
            foreach ($items as $i => $item) {
                if (!self::has($item, 'position')) {
                    $add(self::INVALID, 'BreadcrumbList', 'A ListItem has no position');
                }
                $named = self::has($item, 'name') || (is_array($item['item'] ?? null) && self::has($item['item'], 'name'));
                if (!$named) {
                    $add(self::INVALID, 'BreadcrumbList', 'A ListItem has no name');
                }
                if ($i < count($items) - 1 && !self::has($item, 'item')) {
                    $add(self::INVALID, 'BreadcrumbList', 'A ListItem other than the last has no item URL');
                }
            }
        }
        if ($is(['FAQPage'])) {
            $questions = self::children($node['mainEntity'] ?? null);
            if ($questions === []) {
                $add(self::INVALID, 'FAQPage', 'No Question in mainEntity');
            }
            foreach ($questions as $question) {
                if (!self::has($question, 'name')) {
                    $add(self::INVALID, 'FAQPage', 'A Question has no name');
                }
                $answer = $question['acceptedAnswer'] ?? null;
                $answer = is_array($answer) && array_is_list($answer) ? ($answer[0] ?? null) : $answer;
                if (!is_array($answer) || !self::has($answer, 'text')) {
                    $add(self::INVALID, 'FAQPage', 'A Question has no acceptedAnswer text');
                }
            }
        }
        if ($type !== null && in_array($type, \SeoControlCenter\Domain\Local\NapCheck::LOCAL_TYPES, true)) {
            foreach (['name' => 'Missing name', 'address' => 'Missing address'] as $property => $problem) {
                if (!self::has($node, $property)) {
                    $add(self::INVALID, $type, $problem);
                }
            }
            if (!self::has($node, 'telephone')) {
                $add(self::INCOMPLETE, $type, 'No telephone');
            }
        }
        if ($type !== null && $is(self::EVENTS)) {
            foreach (['name' => 'Missing name', 'startDate' => 'Missing startDate', 'location' => 'Missing location'] as $property => $problem) {
                if (!self::has($node, $property)) {
                    $add(self::INVALID, $type, $problem);
                }
            }
        }
        if ($is(['Recipe'])) {
            foreach (['name' => 'Missing name', 'image' => 'Missing image'] as $property => $problem) {
                if (!self::has($node, $property)) {
                    $add(self::INVALID, 'Recipe', $problem);
                }
            }
        }
        if ($is(self::VIDEOS)) {
            foreach (['name' => 'Missing name', 'thumbnailUrl' => 'Missing thumbnailUrl', 'uploadDate' => 'Missing uploadDate'] as $property => $problem) {
                if (!self::has($node, $property)) {
                    $add(self::INVALID, 'VideoObject', $problem);
                }
            }
        }
        if ($is(['JobPosting'])) {
            foreach (['title' => 'Missing title', 'description' => 'Missing description', 'datePosted' => 'Missing datePosted', 'hiringOrganization' => 'Missing hiringOrganization'] as $property => $problem) {
                if (!self::has($node, $property)) {
                    $add(self::INVALID, 'JobPosting', $problem);
                }
            }
        }

        // Nested items carry their own rules: a review or rating inside a product, a FAQ as a page's main entity.
        foreach (['review', 'aggregateRating', 'mainEntity', 'hasPart', 'subjectOf', 'video'] as $key) {
            if ($is(['FAQPage']) && $key === 'mainEntity') {
                continue;
            }
            foreach (self::children($node[$key] ?? null) as $child) {
                self::node($child, $depth + 1, $add, $haystack, $numbers, $text);
            }
        }
    }

    /**
     * @param array<mixed> $offer
     * @param \Closure(string, string, string): void $add
     * @param list<float>|null $numbers
     */
    private static function offer(array $offer, string $type, \Closure $add, string $haystack, ?array &$numbers, string $text): void
    {
        $aggregate = in_array('AggregateOffer', self::types($offer), true);
        $price = $aggregate ? ($offer['lowPrice'] ?? null) : ($offer['price'] ?? (is_array($offer['priceSpecification'] ?? null) ? ($offer['priceSpecification']['price'] ?? null) : null));
        if ($price === null || $price === '') {
            $add(self::INVALID, $type, $aggregate ? 'AggregateOffer has no lowPrice' : 'Offer has no price');
        }
        $currency = $offer['priceCurrency'] ?? (is_array($offer['priceSpecification'] ?? null) ? ($offer['priceSpecification']['priceCurrency'] ?? null) : null);
        if (!is_string($currency) || preg_match('/^[A-Za-z]{3}$/', trim($currency)) !== 1) {
            $add(self::INVALID, $type, 'Offer has no three-letter priceCurrency');
        }
        if (!$aggregate && !self::has($offer, 'availability')) {
            $add(self::INCOMPLETE, $type, 'Offer has no availability');
        }
        $value = is_int($price) || is_float($price) ? (float) $price : (is_string($price) && is_numeric(trim($price)) ? (float) trim($price) : null);
        if ($value !== null && $value > 0 && $haystack !== '') {
            $numbers ??= self::numbers($text);
            $seen = false;
            foreach ($numbers as $number) {
                if (abs($number - $value) < 0.005) {
                    $seen = true;
                    break;
                }
            }
            if (!$seen) {
                $add(self::MISMATCH, $type, sprintf('Price %s in the markup is not in the page text', rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.')));
            }
        }
    }

    /**
     * Every amount written in the text, read with a comma or a dot as the decimal separator.
     *
     * @return list<float>
     */
    public static function numbers(string $text): array
    {
        preg_match_all('/\d{1,3}(?:[ .,\x{00A0}\x{202F}\']\d{3})+(?:[.,]\d{1,2})?|\d+(?:[.,]\d{1,2})?/u', mb_substr($text, 0, 300_000), $matches);
        $out = [];
        foreach (array_slice($matches[0], 0, 5000) as $raw) {
            $raw = (string) preg_replace('/[\s\x{00A0}\x{202F}\']/u', '', $raw);
            foreach (self::readings($raw) as $reading) {
                $out[] = $reading;
            }
        }

        return $out;
    }

    /**
     * The values an amount can mean: "1.299" is 1299 or 1.299; "29,99" is 29.99.
     *
     * @return list<float>
     */
    private static function readings(string $raw): array
    {
        $readings = [];
        if (preg_match('/^(.*)[.,](\d{1,2})$/', $raw, $m) === 1) {
            $readings[] = (float) (preg_replace('/[.,]/', '', $m[1]) . '.' . $m[2]);
        }
        $readings[] = (float) preg_replace('/[.,]/', '', $raw);

        return $readings;
    }

    private static function schemaContext(mixed $context): bool
    {
        if (is_string($context)) {
            return preg_match('#^https?://schema\.org/?$#i', trim($context)) === 1;
        }
        if (is_array($context)) {
            foreach ($context as $key => $value) {
                if (($key === '@vocab' || is_int($key)) && is_string($value) && preg_match('#^https?://schema\.org/?$#i', trim($value)) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $node
     * @return list<string>
     */
    private static function types(array $node): array
    {
        $types = [];
        foreach (isset($node['@type']) ? (array) $node['@type'] : [] as $type) {
            if (is_string($type)) {
                $types[] = (string) preg_replace('#^https?://schema\.org/#i', '', $type);
            }
        }

        return $types;
    }

    /** @param array<mixed> $node */
    private static function typeName(array $node): ?string
    {
        return self::types($node)[0] ?? null;
    }

    /**
     * A property with a value: not missing, null, an empty string or an empty list.
     *
     * @param array<mixed> $node
     */
    private static function has(array $node, string $property): bool
    {
        $value = $node[$property] ?? null;

        return $value !== null && $value !== [] && !(is_string($value) && trim($value) === '');
    }

    /**
     * One object or a list of them, as a list of objects.
     *
     * @return list<array<mixed>>
     */
    private static function children(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_is_list($value) ? $value : [$value], 'is_array'));
    }

    private static function normalise(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text)));
    }
}

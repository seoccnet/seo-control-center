<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\StructuredData;

/**
 * Builds schema.org JSON-LD for a page (1.5.0) from a few form fields, for
 * the types Google uses for rich results and site names. Empty fields are
 * left out; fields Google requires are reported when empty, so the markup
 * is not pasted half done.
 */
final class SchemaGenerator
{
    /**
     * Field kinds: text, url, textarea, lines (one value per line), qa
     * (question, then answer, blank line between pairs), date, price.
     *
     * @var array<string, array{label: string, fields: array<string, array{0: string, 1: string, 2: bool}>}> type => label, field => [label, kind, required]
     */
    public const array TYPES = [
        'Organization' => ['label' => 'Organization (home page)', 'fields' => [
            'name' => ['Name', 'text', true],
            'url' => ['Website', 'url', true],
            'logo' => ['Logo image URL', 'url', false],
            'description' => ['Description', 'textarea', false],
            'telephone' => ['Phone', 'text', false],
            'email' => ['Email', 'text', false],
            'sameAs' => ['Profiles (one URL per line: social, Wikipedia, directories)', 'lines', false],
        ]],
        'LocalBusiness' => ['label' => 'Local business', 'fields' => [
            'name' => ['Business name', 'text', true],
            'url' => ['Website', 'url', false],
            'image' => ['Photo URL', 'url', false],
            'telephone' => ['Phone', 'text', false],
            'streetAddress' => ['Street and number', 'text', true],
            'postalCode' => ['Postal code', 'text', false],
            'addressLocality' => ['City', 'text', true],
            'addressCountry' => ['Country code (e.g. DE)', 'text', false],
            'openingHours' => ['Opening hours, one per line (e.g. Mo-Fr 09:00-18:00)', 'lines', false],
            'priceRange' => ['Price range (e.g. €€)', 'text', false],
        ]],
        'WebSite' => ['label' => 'Website (site name in results)', 'fields' => [
            'name' => ['Site name', 'text', true],
            'alternateName' => ['Other name (abbreviation)', 'text', false],
            'url' => ['Home page URL', 'url', true],
        ]],
        'Article' => ['label' => 'Article or blog post', 'fields' => [
            'headline' => ['Headline', 'text', true],
            'description' => ['Summary', 'textarea', false],
            'image' => ['Image URL', 'url', false],
            'author' => ['Author name', 'text', true],
            'authorUrl' => ['Author profile URL', 'url', false],
            'datePublished' => ['Published on', 'date', true],
            'dateModified' => ['Updated on', 'date', false],
            'publisher' => ['Publisher (site or company name)', 'text', false],
        ]],
        'Product' => ['label' => 'Product', 'fields' => [
            'name' => ['Product name', 'text', true],
            'description' => ['Description', 'textarea', false],
            'image' => ['Image URL', 'url', true],
            'brand' => ['Brand', 'text', false],
            'sku' => ['SKU', 'text', false],
            'price' => ['Price (e.g. 49.90)', 'price', true],
            'priceCurrency' => ['Currency (e.g. EUR)', 'text', true],
            'availability' => ['Availability (InStock, OutOfStock, PreOrder)', 'text', false],
            'ratingValue' => ['Average rating (only if reviews are shown on the page)', 'text', false],
            'reviewCount' => ['Number of reviews', 'text', false],
        ]],
        'FAQPage' => ['label' => 'FAQ', 'fields' => [
            'qa' => ['Questions and answers: a question line, its answer on the next lines, a blank line between pairs', 'qa', true],
        ]],
        'BreadcrumbList' => ['label' => 'Breadcrumbs', 'fields' => [
            'trail' => ['Trail, one "Name | URL" per line from the home page down', 'lines', true],
        ]],
    ];

    private const array AVAILABILITY = ['instock' => 'InStock', 'outofstock' => 'OutOfStock', 'preorder' => 'PreOrder', 'backorder' => 'BackOrder', 'discontinued' => 'Discontinued', 'limitedavailability' => 'LimitedAvailability', 'soldout' => 'SoldOut'];

    /**
     * @param array<string, string> $input field => value
     * @return array{json: string, missing: list<string>, notes: list<string>}
     */
    public static function generate(string $type, array $input, string $pageUrl): array
    {
        $spec = self::TYPES[$type] ?? throw new \InvalidArgumentException('Unknown schema type.');
        $value = static fn(string $field): string => trim((string) preg_replace('/[ \t]+/u', ' ', (string) ($input[$field] ?? '')));
        $missing = [];
        foreach ($spec['fields'] as $field => [$label, , $required]) {
            if ($required && $value($field) === '') {
                $missing[] = $label;
            }
        }
        $notes = [];
        $data = ['@context' => 'https://schema.org', '@type' => $type];
        $lines = static fn(string $field): array => array_values(array_filter(array_map('trim', preg_split('/\R/u', $value($field)) ?: []), static fn(string $l): bool => $l !== ''));
        $set = static function (array &$target, string $key, string $v): void {
            if ($v !== '') {
                $target[$key] = $v;
            }
        };

        switch ($type) {
            case 'Organization':
                foreach (['name', 'url', 'logo', 'description', 'telephone', 'email'] as $f) {
                    $set($data, $f, $value($f));
                }
                if ($lines('sameAs') !== []) {
                    $data['sameAs'] = $lines('sameAs');
                }
                break;
            case 'LocalBusiness':
                foreach (['name', 'url', 'image', 'telephone', 'priceRange'] as $f) {
                    $set($data, $f, $value($f));
                }
                $address = ['@type' => 'PostalAddress'];
                foreach (['streetAddress', 'postalCode', 'addressLocality'] as $f) {
                    $set($address, $f, $value($f));
                }
                $set($address, 'addressCountry', strtoupper($value('addressCountry')));
                if (count($address) > 1) {
                    $data['address'] = $address;
                }
                if ($lines('openingHours') !== []) {
                    $data['openingHours'] = $lines('openingHours');
                }
                $notes[] = 'Use a more specific type than LocalBusiness when one fits (Restaurant, Dentist, Store…): replace the @type.';
                break;
            case 'WebSite':
                foreach (['name', 'alternateName', 'url'] as $f) {
                    $set($data, $f, $value($f));
                }
                $notes[] = 'Place it on the home page only.';
                break;
            case 'Article':
                $data['@type'] = 'Article';
                $set($data, 'headline', mb_substr($value('headline'), 0, 110));
                $set($data, 'description', $value('description'));
                $set($data, 'image', $value('image'));
                if ($value('author') !== '') {
                    $author = ['@type' => 'Person', 'name' => $value('author')];
                    $set($author, 'url', $value('authorUrl'));
                    $data['author'] = $author;
                }
                $set($data, 'datePublished', $value('datePublished'));
                $set($data, 'dateModified', $value('dateModified'));
                if ($value('publisher') !== '') {
                    $data['publisher'] = ['@type' => 'Organization', 'name' => $value('publisher')];
                }
                $data['mainEntityOfPage'] = $pageUrl;
                if (mb_strlen($value('headline')) > 110) {
                    $notes[] = 'The headline was cut to 110 characters.';
                }
                break;
            case 'Product':
                foreach (['name', 'description', 'image', 'sku'] as $f) {
                    $set($data, $f, $value($f));
                }
                if ($value('brand') !== '') {
                    $data['brand'] = ['@type' => 'Brand', 'name' => $value('brand')];
                }
                $offer = ['@type' => 'Offer', 'url' => $pageUrl];
                $price = str_replace(',', '.', $value('price'));
                if ($price !== '') {
                    if (preg_match('/^\d+(\.\d{1,2})?$/', $price) !== 1) {
                        $notes[] = 'The price must be a plain number such as 49.90, without currency signs.';
                    }
                    $offer['price'] = $price;
                }
                $set($offer, 'priceCurrency', strtoupper($value('priceCurrency')));
                $availability = self::AVAILABILITY[strtolower(str_replace([' ', '_', '-'], '', $value('availability')))] ?? null;
                if ($availability !== null) {
                    $offer['availability'] = 'https://schema.org/' . $availability;
                }
                $data['offers'] = $offer;
                if ($value('ratingValue') !== '' && $value('reviewCount') !== '') {
                    $data['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $value('ratingValue'), 'reviewCount' => $value('reviewCount')];
                }
                break;
            case 'FAQPage':
                $pairs = [];
                foreach (preg_split('/\R\s*\R/u', trim((string) ($input['qa'] ?? ''))) ?: [] as $block) {
                    $parts = array_values(array_filter(array_map('trim', preg_split('/\R/u', $block) ?: []), static fn(string $l): bool => $l !== ''));
                    if (count($parts) < 2) {
                        continue;
                    }
                    $pairs[] = ['@type' => 'Question', 'name' => array_shift($parts), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => implode(' ', $parts)]];
                }
                if ($pairs !== []) {
                    $data['mainEntity'] = $pairs;
                }
                $notes[] = 'Google shows FAQ rich results only for well-known government and health sites; the markup still describes the page for other search engines and AI answers. Every question and answer must be visible on the page.';
                break;
            case 'BreadcrumbList':
                $items = [];
                foreach ($lines('trail') as $i => $line) {
                    [$name, $url] = array_map('trim', array_pad(explode('|', $line, 2), 2, ''));
                    $item = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name];
                    if ($url !== '') {
                        $item['item'] = $url;
                    }
                    $items[] = $item;
                }
                if ($items !== []) {
                    $data['itemListElement'] = $items;
                }
                break;
        }

        return [
            'json' => json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'missing' => $missing,
            'notes' => $notes,
        ];
    }

    /**
     * A breadcrumb trail from a URL's folders, as a starting point: "Home"
     * for the root, each folder named from its slug.
     */
    public static function trailFor(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
        $lines = ['Home | ' . $origin . '/'];
        $path = '';
        foreach (array_values(array_filter(explode('/', (string) ($parts['path'] ?? '')), static fn(string $s): bool => $s !== '')) as $segment) {
            $path .= '/' . $segment;
            $name = ucfirst(trim((string) preg_replace('/[-_]+/', ' ', (string) preg_replace('/\.(html?|php)$/i', '', rawurldecode($segment)))));
            $lines[] = $name . ' | ' . $origin . $path;
        }

        return implode("\n", $lines);
    }
}

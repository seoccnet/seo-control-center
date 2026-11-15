<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\Crawler\StructuredDataCheck;
use SeoControlCenter\Modules\SiteAudit\IssueDetector;
use SeoControlCenter\Modules\SiteAudit\IssueType;

final class StructuredDataCheckTest extends TestCase
{
    public function testACompleteProductMatchingThePageHasNoProblems(): void
    {
        $page = self::parse([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => 'Garden Hose 25 m',
            'image' => 'https://acme.test/hose.jpg',
            'offers' => ['@type' => 'Offer', 'price' => '1299.00', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'],
        ], '<h1>Garden hose 25 m</h1><p>Now only € 1.299,00</p>');

        self::assertSame([], $page->schemaProblems);
    }

    public function testRequiredRecommendedAndMismatchedProperties(): void
    {
        $page = self::parse([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Product', 'name' => 'Rain Barrel', 'offers' => ['@type' => 'Offer', 'price' => 49.5]],
                ['@type' => 'BreadcrumbList', 'itemListElement' => [['@type' => 'ListItem', 'name' => 'Home'], ['@type' => 'ListItem', 'position' => 2, 'name' => 'Barrels']]],
                ['@type' => 'BlogPosting', 'headline' => 'Tips'],
            ],
        ], '<h1>Water butt</h1><p>Price 39,50</p>');
        $problems = array_map(static fn(array $p): string => $p['level'] . ' ' . $p['type'] . ': ' . $p['problem'], $page->schemaProblems ?? []);

        self::assertContains('invalid Product: Offer has no three-letter priceCurrency', $problems);
        self::assertContains('incomplete Product: No image', $problems);
        self::assertContains('incomplete Product: Offer has no availability', $problems);
        self::assertContains('mismatch Product: Name "Rain Barrel" is not in the page text', $problems);
        self::assertContains('mismatch Product: Price 49.5 in the markup is not in the page text', $problems);
        self::assertContains('invalid BreadcrumbList: A ListItem has no position', $problems);
        self::assertContains('invalid BreadcrumbList: A ListItem other than the last has no item URL', $problems);
        self::assertContains('incomplete BlogPosting: No datePublished', $problems);
    }

    public function testBrokenJsonAndMissingContextAreInvalid(): void
    {
        $page = HtmlPage::parse('<script type="application/ld+json">{"@type": "Product",}</script><script type="application/ld+json">{"@type": "Organization", "name": "Acme"}</script>', 'https://acme.test/');
        $problems = array_column($page->schemaProblems ?? [], 'problem');

        self::assertStringStartsWith('JSON-LD does not parse', $problems[0]);
        self::assertSame('No @context, so search engines do not read it as schema.org', $problems[1]);
    }

    public function testNestedRatingsAndFaqAnswers(): void
    {
        $problems = StructuredDataCheck::check([['json' => [
            '@context' => ['@vocab' => 'http://schema.org/'],
            '@type' => 'Product',
            'name' => 'Acme',
            'image' => 'x.jpg',
            'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => 4.5],
            'review' => [['@type' => 'Review', 'author' => 'Ann']],
        ]], ['json' => ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => [['@type' => 'Question', 'name' => 'Why?', 'acceptedAnswer' => ['@type' => 'Answer']]]]]], 'Acme');
        $problems = array_map(static fn(array $p): string => $p['type'] . ': ' . $p['problem'], $problems);

        self::assertContains('AggregateRating: Needs ratingCount or reviewCount', $problems);
        self::assertContains('Review: Missing reviewRating with ratingValue', $problems);
        self::assertContains('FAQPage: A Question has no acceptedAnswer text', $problems);
    }

    public function testPagesWithoutJsonLdAreNotChecked(): void
    {
        self::assertSame([], HtmlPage::parse('<p>No markup</p>', 'https://acme.test/')->schemaProblems);
    }

    public function testProblemsBecomeIssuesOnIndexablePages(): void
    {
        $problems = [
            ['level' => 'invalid', 'type' => 'Product', 'problem' => 'Offer has no price'],
            ['level' => 'invalid', 'type' => 'Product', 'problem' => 'Missing name'],
            ['level' => 'mismatch', 'type' => 'Product', 'problem' => 'Name "X" is not in the page text'],
        ];
        $page = new AuditPage(1, 'https://acme.test/p', 1, 'done', 200, 'text/html', title: 'Product page', description: 'A product', h1Count: 1, wordCount: 400, schemaTypes: ['Product'], schemaProblems: $problems);
        $hidden = new AuditPage(2, 'https://acme.test/q', 1, 'done', 200, 'text/html', noindex: true, schemaProblems: $problems);
        $issues = HreflangCheckTest::byPage(IssueDetector::detect([$page, $hidden], [])['issues']);

        self::assertSame('Product: Offer has no price; Product: Missing name', $issues[1][IssueType::InvalidStructuredData->value]);
        self::assertArrayHasKey(IssueType::StructuredDataMismatch->value, $issues[1]);
        self::assertArrayNotHasKey(IssueType::IncompleteStructuredData->value, $issues[1]);
        self::assertArrayNotHasKey(IssueType::InvalidStructuredData->value, $issues[2] ?? []);
    }

    public function testReadsAmountsInEitherNotation(): void
    {
        $numbers = StructuredDataCheck::numbers('1,299.00 or 1.299,00 or 29,99 or 1 299 or 5');

        foreach ([1299.0, 29.99, 5.0] as $value) {
            self::assertContains($value, $numbers);
        }
    }

    /**
     * @param array<string, mixed> $json
     */
    private static function parse(array $json, string $body): HtmlPage
    {
        return HtmlPage::parse('<html><head><title>Shop</title><script type="application/ld+json">' . json_encode($json, JSON_THROW_ON_ERROR) . '</script></head><body>' . $body . '</body></html>', 'https://acme.test/p');
    }
}

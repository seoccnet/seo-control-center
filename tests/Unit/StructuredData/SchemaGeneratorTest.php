<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\StructuredData;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\StructuredData\SchemaGenerator;

/** JSON-LD from form fields (1.5.0). */
final class SchemaGeneratorTest extends TestCase
{
    public function testProductWithOfferAndRating(): void
    {
        $result = SchemaGenerator::generate('Product', ['name' => 'Red  shoe', 'image' => 'https://acme.test/red.jpg', 'brand' => 'Acme', 'price' => '49,90', 'priceCurrency' => 'eur', 'availability' => 'in stock', 'ratingValue' => '4.6', 'reviewCount' => '12'], 'https://acme.test/red');

        self::assertSame([], $result['missing']);
        $data = json_decode($result['json'], true);
        self::assertIsArray($data);
        self::assertSame('Red shoe', $data['name']);
        self::assertSame(['@type' => 'Brand', 'name' => 'Acme'], $data['brand']);
        self::assertSame(['@type' => 'Offer', 'url' => 'https://acme.test/red', 'price' => '49.90', 'priceCurrency' => 'EUR', 'availability' => 'https://schema.org/InStock'], $data['offers']);
        self::assertSame('4.6', $data['aggregateRating']['ratingValue'] ?? null);
        self::assertArrayNotHasKey('sku', $data, 'Empty fields are left out.');
    }

    public function testMissingRequiredFieldsAndNotes(): void
    {
        $result = SchemaGenerator::generate('Product', ['name' => 'Shoe', 'price' => '€ 49'], 'https://acme.test/p');
        self::assertSame(['Image URL', 'Currency (e.g. EUR)'], $result['missing']);
        self::assertStringContainsString('plain number', implode(' ', $result['notes']));
        $this->expectException(\InvalidArgumentException::class);
        SchemaGenerator::generate('Recipe', [], 'https://acme.test/');
    }

    public function testFaqBreadcrumbsArticleAndBusiness(): void
    {
        $faq = json_decode(SchemaGenerator::generate('FAQPage', ['qa' => "Do they stretch?\nYes, a little.\nAfter a week.\n\nLonely line\n\r\nWhat sizes?\n36 to 46."], 'https://acme.test/faq')['json'], true);
        self::assertIsArray($faq);
        self::assertSame([
            ['@type' => 'Question', 'name' => 'Do they stretch?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Yes, a little. After a week.']],
            ['@type' => 'Question', 'name' => 'What sizes?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '36 to 46.']],
        ], $faq['mainEntity']);

        $trail = SchemaGenerator::trailFor('https://acme.test/shoes/red-running_shoes.html?x=1');
        self::assertSame("Home | https://acme.test/\nShoes | https://acme.test/shoes\nRed running shoes | https://acme.test/shoes/red-running_shoes.html", $trail);
        $crumbs = json_decode(SchemaGenerator::generate('BreadcrumbList', ['trail' => $trail], 'https://acme.test/x')['json'], true);
        self::assertIsArray($crumbs);
        self::assertSame(['@type' => 'ListItem', 'position' => 3, 'name' => 'Red running shoes', 'item' => 'https://acme.test/shoes/red-running_shoes.html'], $crumbs['itemListElement'][2]);

        $article = json_decode(SchemaGenerator::generate('Article', ['headline' => str_repeat('a', 120), 'author' => 'Kim', 'datePublished' => '2026-11-01'], 'https://acme.test/blog/x')['json'], true);
        self::assertIsArray($article);
        self::assertSame(110, mb_strlen($article['headline']));
        self::assertSame(['@type' => 'Person', 'name' => 'Kim'], $article['author']);
        self::assertSame('https://acme.test/blog/x', $article['mainEntityOfPage']);

        $shop = json_decode(SchemaGenerator::generate('LocalBusiness', ['name' => 'Acme', 'streetAddress' => 'Main St 1', 'addressLocality' => 'Berlin', 'addressCountry' => 'de', 'openingHours' => "Mo-Fr 09:00-18:00\n\nSa 10:00-14:00"], 'https://acme.test/')['json'], true);
        self::assertIsArray($shop);
        self::assertSame(['@type' => 'PostalAddress', 'streetAddress' => 'Main St 1', 'addressLocality' => 'Berlin', 'addressCountry' => 'DE'], $shop['address']);
        self::assertSame(['Mo-Fr 09:00-18:00', 'Sa 10:00-14:00'], $shop['openingHours']);
    }
}

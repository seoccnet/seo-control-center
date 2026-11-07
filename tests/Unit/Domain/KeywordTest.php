<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Shared\ValidationException;

final class KeywordTest extends TestCase
{
    public function testNormalizesCaseAndWhitespace(): void
    {
        self::assertSame('red running shoes', Keyword::normalize("  Red \t Running   SHOES "));
        self::assertSame('größe schuhe', Keyword::normalize('Größe Schuhe'));
        self::assertSame(Keyword::hash('red shoes'), Keyword::hash(Keyword::normalize('Red  Shoes')));
    }

    public function testNormalizesEveryScriptToOneUnicodeForm(): void
    {
        // 0.44.3: one keyword whatever the keyboard, the copy and paste or the script.
        self::assertSame(Keyword::normalize("caf\u{00E9}"), Keyword::normalize("cafe\u{0301}"), 'composed and decomposed é');
        self::assertSame('sjemenke kanabisa', Keyword::normalize("Sjemenke\u{00A0}kanabisa"), 'non-breaking space');
        self::assertSame('семена конопље', Keyword::normalize("\u{FEFF}Семена\u{200B} \u{3000}КОНОПЉЕ"), 'byte order mark, zero-width and ideographic spaces');
        self::assertSame('大麻 种子', Keyword::normalize('大麻　种子'));
        self::assertSame('ελληνικά σπόροι', Keyword::normalize('ΕΛΛΗΝΙΚΆ ΣΠΌΡΟΙ'));
        self::assertNull(Keyword::problem(Keyword::normalize('بذور القنب')), 'Arabic is a valid keyword');
        self::assertNotNull(Keyword::problem(Keyword::normalize("seeds \xC3\x28")), 'invalid UTF-8 is refused, not silently dropped');
    }

    public function testEveryGoogleAdsCountryAndSearchLanguageIsAMarket(): void
    {
        self::assertCount(244, KeywordLocale::LOCATIONS, 'countries and top-level territories such as Taiwan and Hong Kong');
        self::assertCount(128, KeywordLocale::LANGUAGES);
        self::assertArrayNotHasKey(2643, KeywordLocale::LOCATIONS, 'DataForSEO serves no location in Russia');
        self::assertSame('2392:ja', KeywordLocale::fromInput('2392', 'ja')->key());
        self::assertSame('Taiwan · Chinese (Traditional)', KeywordLocale::fromKey('2158:zh-TW')?->label());
        self::assertSame('2076:pt-BR', KeywordLocale::fromKey('2076:pt-BR')?->key());
        self::assertNull(KeywordLocale::fromKey('2840:xx'));
        self::assertSame(['Afghanistan', 'Albania'], array_slice(array_values(KeywordLocale::LOCATIONS), 0, 2), 'sorted by name');

        self::assertNull(\SeoControlCenter\Domain\Keywords\Markets::adsLanguage('bs'), 'finding 41: Google Ads has no Bosnian');
        self::assertSame('hr', \SeoControlCenter\Domain\Keywords\Markets::adsLanguage('hr'));
        self::assertTrue(\SeoControlCenter\Domain\Keywords\Markets::labsSupports(2070, 'bs'));
        self::assertSame(['fr', 'nl', 'de'], \SeoControlCenter\Domain\Keywords\Markets::suggested(2056));
        self::assertSame('DataForSEO keyword research has no data for Croatia in English. Choose Croatian.', \SeoControlCenter\Domain\Keywords\Markets::labsProblem(2191, 'en'));
        self::assertSame('DataForSEO keyword research has no data for Afghanistan. Choose another country for this lookup.', \SeoControlCenter\Domain\Keywords\Markets::labsProblem(2004, 'en'));
        self::assertNull(\SeoControlCenter\Domain\Keywords\Markets::labsProblem(2840, 'en'));
    }

    public function testParseListDeduplicatesAndSkipsBlankLines(): void
    {
        self::assertSame(['red shoes', 'blue shoes'], Keyword::parseList("Red Shoes\n\nred shoes\r\nblue shoes\n", 10));
    }

    public function testParseListReportsInvalidKeywords(): void
    {
        try {
            Keyword::parseList("ok keyword\nwhat? now\n" . str_repeat('a', 81) . "\none two three four five six seven eight nine ten eleven", 10);
            self::fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $message = $e->errors()['keywords'];
            self::assertStringContainsString('"what? now" contains characters', $message);
            self::assertStringContainsString('longer than 80 characters', $message);
            self::assertStringContainsString('more than 10 words', $message);
            self::assertStringNotContainsString('ok keyword', $message);
        }
    }

    public function testParseListRequiresInputAndEnforcesMaximum(): void
    {
        try {
            Keyword::parseList("  \n ", 10);
            self::fail('Expected a validation error.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('at least one keyword', $e->errors()['keywords']);
        }

        $this->expectException(ValidationException::class);
        Keyword::parseList("a\nb\nc", 2);
    }

    public function testLocaleValidation(): void
    {
        $locale = KeywordLocale::fromInput('2276', 'de');
        self::assertSame('Germany · German', $locale->label());
        self::assertSame('2276:de', $locale->key());

        try {
            KeywordLocale::fromInput('9999', 'xx');
            self::fail('Expected a validation error.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('location', $e->errors());
            self::assertArrayHasKey('language', $e->errors());
        }
    }
}

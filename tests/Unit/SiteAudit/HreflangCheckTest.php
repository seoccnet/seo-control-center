<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\Crawler\Hreflang;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\IssueDetector;
use SeoControlCenter\Modules\SiteAudit\IssueType;

final class HreflangCheckTest extends TestCase
{
    public function testCodesGoogleReadsAreValid(): void
    {
        foreach (['de', 'en-gb', 'EN-GB', 'es-419', 'zh-hant', 'zh-hant-tw', 'x-default', 'pt-BR'] as $code) {
            self::assertNull(Hreflang::problem($code), $code);
        }
    }

    public function testWrongCodesAreExplained(): void
    {
        self::assertStringContainsString('en-GB', (string) Hreflang::problem('en-uk'));
        self::assertStringContainsString('en-US', (string) Hreflang::problem('us-en'));
        self::assertStringContainsString('hyphen', (string) Hreflang::problem('en_GB'));
        self::assertStringContainsString('EU is not a country', (string) Hreflang::problem('en-eu'));
        self::assertStringContainsString('not a language code', (string) Hreflang::problem('english'));
        self::assertStringContainsString('ISO 639-1', (string) Hreflang::problem('xx'));
        self::assertStringContainsString('ISO 3166-1', (string) Hreflang::problem('de-zz'));
        self::assertSame('en', Hreflang::language('en-GB'));
        self::assertNull(Hreflang::language('x-default'));
    }

    public function testParsesAlternatesAndHtmlLang(): void
    {
        $page = HtmlPage::parse(
            '<html lang="DE-de"><head>'
            . '<link rel="alternate" hreflang="de" href="/de/">'
            . '<link rel="alternate" hreflang="EN" href="https://shop.acme.test/en/">'
            . '<link rel="stylesheet" hreflang="fr" href="/fr.css">'
            . '<link rel="alternate" hreflang="" href="/blank/">'
            . '</head><body></body></html>',
            'https://shop.acme.test/de/',
        );

        self::assertSame([['lang' => 'de', 'url' => 'https://shop.acme.test/de/'], ['lang' => 'en', 'url' => 'https://shop.acme.test/en/']], $page->hreflang);
        self::assertSame('de-de', $page->htmlLang);
        self::assertSame([], HtmlPage::parse('<p>Plain</p>', 'https://shop.acme.test/')->hreflang);
    }

    public function testAMatchingSetHasNoIssues(): void
    {
        $set = [['lang' => 'de', 'url' => 'https://acme.test/de/'], ['lang' => 'en', 'url' => 'https://acme.test/en/'], ['lang' => 'x-default', 'url' => 'https://acme.test/en/']];
        $pages = [self::page(1, '/de/', $set, 'de'), self::page(2, '/en/', $set, 'en')];

        self::assertSame([], self::hreflangIssues($pages));
    }

    public function testFindsBrokenSets(): void
    {
        $pages = [
            self::page(1, '/de/', [
                ['lang' => 'de', 'url' => 'https://acme.test/de/'],
                ['lang' => 'en', 'url' => 'https://acme.test/en/'],
                ['lang' => 'fr', 'url' => 'https://acme.test/fr/'],
                ['lang' => 'it', 'url' => 'https://acme.test/it/'],
                ['lang' => 'it', 'url' => 'https://acme.test/it-old/'],
                ['lang' => 'en-uk', 'url' => 'https://acme.test/uk/'],
                ['lang' => 'es', 'url' => 'https://other.test/es/'],
            ], 'en'),
            // Does not link back to /de/.
            self::page(2, '/en/', [['lang' => 'en', 'url' => 'https://acme.test/en/']], 'en'),
            new AuditPage(3, 'https://acme.test/fr/', 1, 'done', 301, 'text/html', 'https://acme.test/fr/home'),
            self::page(4, '/it/', null, 'it'),
            // Lists others but not itself.
            self::page(5, '/nl/', [['lang' => 'de', 'url' => 'https://acme.test/de/']], 'nl'),
        ];
        $found = self::byPage(self::hreflangIssues($pages));

        self::assertStringContainsString('en-GB', $found[1]['hreflang_invalid_code']);
        self::assertStringContainsString('it → /it/ and /it-old/', $found[1]['hreflang_conflict']);
        self::assertSame('fr → /fr/ (redirects (301))', $found[1]['hreflang_bad_target']);
        // Only /en/ is known not to link back: /it/ was crawled before hreflang was recorded, other.test was not crawled.
        self::assertSame('Not linking back: en → /en/', $found[1]['hreflang_no_return']);
        self::assertSame('5 language versions, no x-default', $found[1]['hreflang_missing_default']);
        self::assertSame('html lang="en", hreflang de', $found[1]['hreflang_lang_mismatch']);
        self::assertArrayHasKey('hreflang_missing_self', $found[5]);
        self::assertArrayNotHasKey(2, $found);
    }

    public function testHreflangWarningsCountAgainstTheScore(): void
    {
        self::assertSame('warning', IssueType::HreflangNoReturn->severity());
        self::assertSame('notice', IssueType::HreflangMissingDefault->severity());
    }

    /**
     * @param list<array{lang: string, url: string}>|null $hreflang
     */
    private static function page(int $id, string $path, ?array $hreflang, ?string $lang): AuditPage
    {
        return new AuditPage($id, 'https://acme.test' . $path, 1, 'done', 200, 'text/html', title: 'Page ' . $id, description: 'About page ' . $id, h1Count: 1, wordCount: 400, schemaTypes: ['WebPage'], hreflang: $hreflang, htmlLang: $lang);
    }

    /**
     * @param list<array{pageId: int, type: IssueType, detail: ?string}> $issues
     * @return array<int, array<string, string>>
     */
    public static function byPage(array $issues): array
    {
        $found = [];
        foreach ($issues as $issue) {
            $found[$issue['pageId']][$issue['type']->value] = (string) $issue['detail'];
        }

        return $found;
    }

    /**
     * @param list<AuditPage> $pages
     * @return list<array{pageId: int, type: IssueType, detail: ?string}>
     */
    private static function hreflangIssues(array $pages): array
    {
        return array_values(array_filter(IssueDetector::detect($pages, [])['issues'], static fn(array $i): bool => str_starts_with($i['type']->value, 'hreflang_')));
    }
}

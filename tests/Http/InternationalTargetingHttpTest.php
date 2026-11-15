<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\International\CountryCodes;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/** Countries next to the hreflang setup on the site audit page (1.2.1). */
final class InternationalTargetingHttpTest extends TestCase
{
    public function testCountriesAreMatchedToRegionalVersions(): void
    {
        $s = new Scenario();
        $set = [['lang' => 'en-gb', 'url' => 'https://shop.acme.test/uk/'], ['lang' => 'de-de', 'url' => 'https://shop.acme.test/de/'], ['lang' => 'x-default', 'url' => 'https://shop.acme.test/'], ['lang' => 'en-uk', 'url' => 'https://shop.acme.test/bad/']];
        $this->audit($s, [['/uk/', $set], ['/de/', $set], ['/', $set]]);
        $this->countries($s, [new DimensionRow('gbr', new Metrics(50, 900, 4.0)), new DimensionRow('usa', new Metrics(30, 800, 6.0)), new DimensionRow('zzz', new Metrics(1, 10, 9.0))]);

        $page = (string) $s->loggedIn($s->owner)->get('/projects/' . $s->acmeProject->publicId . '/site-audit')->getBody();
        self::assertStringContainsString('Countries and language versions', $page);
        self::assertStringContainsString('<code>en-gb</code> <span class="muted small">(1 page)</span>', $page);
        self::assertStringNotContainsString('<code>en-uk</code>', $page, 'Invalid codes are reported as issues, not listed as versions.');
        self::assertMatchesRegularExpression('#<td>GBR</td><td>50</td><td>900</td>\s*<td>en-gb</td>#', $page);
        self::assertMatchesRegularExpression('#<td>USA</td>.*?None: served by a language-only version or x-default#s', $page);
        self::assertStringContainsString('<td>ZZZ</td>', $page, 'Unknown countries are listed without a match.');
    }

    public function testLanguageOnlySitesAndSitesWithoutHreflang(): void
    {
        $s = new Scenario();
        $set = [['lang' => 'en', 'url' => 'https://shop.acme.test/en/'], ['lang' => 'de', 'url' => 'https://shop.acme.test/de/']];
        $this->audit($s, [['/en/', $set], ['/de/', $set]]);
        $this->countries($s, [new DimensionRow('deu', new Metrics(5, 50, 3.0))]);
        $page = (string) $s->loggedIn($s->owner)->get('/projects/' . $s->acmeProject->publicId . '/site-audit')->getBody();
        self::assertStringContainsString('The site targets languages only', $page);
        self::assertStringContainsString('no x-default', $page);

        $plain = new Scenario();
        $this->audit($plain, [['/', []]]);
        self::assertStringNotContainsString('Countries and language versions', (string) $plain->loggedIn($plain->owner)->get('/projects/' . $plain->acmeProject->publicId . '/site-audit')->getBody());
    }

    public function testCountryCodes(): void
    {
        self::assertSame('gb', CountryCodes::alpha2('gbr'));
        self::assertSame('de', CountryCodes::alpha2('DEU'));
        self::assertSame('xk', CountryCodes::alpha2('xkk'));
        self::assertNull(CountryCodes::alpha2('zzz'));
    }

    /**
     * @param list<array{string, list<array{lang: string, url: string}>}> $pages path and hreflang
     */
    private function audit(Scenario $s, array $pages): void
    {
        $audits = $s->container->get(InMemorySiteAudit::class);
        $audit = $audits->create($s->acmeProject->id, $s->acme->id, 'https://shop.acme.test/', 10, 'manual', null);
        $first = $audits->pendingPages($audit->id, 1)[0];
        $audits->pages[$audit->id] = [];
        foreach ($pages as $i => [$path, $hreflang]) {
            $id = $first->id + $i;
            $audits->pages[$audit->id][$id] = new AuditPage($id, 'https://shop.acme.test' . $path, $i === 0 ? 0 : 1, 'done', 200, 'text/html', title: 'Page ' . $i, description: 'About ' . $i, h1Count: 1, wordCount: 400, schemaTypes: ['WebPage'], hreflang: $hreflang, htmlLang: 'en');
        }
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
    }

    /** @param list<DimensionRow> $rows */
    private function countries(Scenario $s, array $rows): void
    {
        $yesterday = $s->container->get(\SeoControlCenter\Core\Support\Clock::class)->now()->modify('-1 day')->format('Y-m-d');
        $s->container->get(SearchPerformanceStore::class)->replaceDay($s->acmeProject->id, $yesterday, ['query' => [], 'page' => [], 'country' => $rows]);
    }
}

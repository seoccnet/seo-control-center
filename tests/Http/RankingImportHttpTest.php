<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Rankings\RankingImportService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankingImports;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Scenario;

/** Import of keyword lists and ranking history (1.3.0). */
final class RankingImportHttpTest extends TestCase
{
    private Scenario $s;
    private RankingImportService $imports;

    protected function setUp(): void
    {
        $this->s = new Scenario(clock: new FrozenClock(new \DateTimeImmutable('2026-11-16 10:00:00')));
        $this->imports = $this->s->container->get(RankingImportService::class);
    }

    public function testImportsHistoryWithoutOverwritingChecks(): void
    {
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        $market = KeywordLocale::default();
        $keywords->add($this->s->acmeProject->id, ['rain barrel'], $market, $this->s->owner->id);
        $barrel = $keywords->keywords[$this->s->acmeProject->id][0]['id'];
        // SEOcc checked this keyword on 2026-10-08 itself.
        $rankings->snapshots[$barrel . ':desktop:2026-10-08'] = new RankSnapshot($barrel, $this->s->acmeProject->id, SerpDevice::Desktop, '2026-10-08', 2, 2, null, [], []);

        $result = $this->imports->import($this->s->owner, $this->s->acmeProject, "Keyword,2026-10-01,2026-10-08,2030-01-01\nRain Barrel,4,9,1\ngarden hose,12,-,3\nbad<keyword>,1,1,1\n", $market->key(), 'desktop', '2026-11-16', ClientInfo::cli());

        self::assertSame(['wide', 1, 0, 1], [$result['layout'], $result['keywordsAdded'], $result['keywordsSkipped'], $result['invalidKeywords']]);
        self::assertSame([3, 1, 2, 2], [$result['positions'], $result['existing'], $result['days'], $result['ignoredRows']], 'The checked day is kept; the future date is skipped.');
        self::assertSame(2, $rankings->snapshots[$barrel . ':desktop:2026-10-08']->position);
        self::assertSame(4, $rankings->snapshots[$barrel . ':desktop:2026-10-01']->position);
        self::assertCount(3, $this->s->container->get(InMemoryRankingImports::class)->imported);
        self::assertNotNull($rankings->latestDay($this->s->acmeProject->id, SerpDevice::Desktop), 'Daily totals are rebuilt.');
        self::assertContains('rankings.history_imported', $this->s->audit()->actions());
        self::assertSame([], $keywords->requests, 'No paid lookup is started.');
    }

    public function testCurrentPositionsUseTheChosenDateAndDevice(): void
    {
        $rankings = $this->s->container->get(InMemoryRankings::class);
        $result = $this->imports->import($this->s->owner, $this->s->acmeProject, "Keyword,Position,URL\nrain barrel,7,https://shop.acme.test/barrel\n", KeywordLocale::default()->key(), 'mobile', '2026-11-10', ClientInfo::cli());
        self::assertSame(1, $result['positions']);
        $snapshot = array_values($rankings->snapshots)[0];
        self::assertSame([SerpDevice::Mobile, '2026-11-10', 7, 'https://shop.acme.test/barrel'], [$snapshot->device, $snapshot->checkedOn, $snapshot->position, $snapshot->url]);

        $this->expectException(ValidationException::class);
        $this->imports->import($this->s->owner, $this->s->acmeProject, "Keyword\nx\n", KeywordLocale::default()->key(), 'desktop', '2026-12-01', ClientInfo::cli());
    }

    public function testPageAndPermissions(): void
    {
        $base = '/projects/' . $this->s->acmeProject->publicId . '/rankings/import';
        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($base)->getBody();
        self::assertStringContainsString('Bring your keywords and ranking history', $page);
        self::assertStringContainsString('name="file"', $page);
        self::assertStringContainsString('/rankings/import">import them', (string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/rankings')->getBody());
        $owner->get($base);
        self::assertSame(303, $owner->post($base, ['market' => KeywordLocale::default()->key(), 'device' => 'desktop', 'date' => '2026-11-16'])->getStatusCode(), 'Without a file the page explains the problem.');

        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringNotContainsString('name="file"', (string) $viewer->get($base)->getBody());
        $viewer->get('/account');
        self::assertSame(403, $viewer->post($base, ['_csrf' => $viewer->csrfToken(), 'market' => KeywordLocale::default()->key()])->getStatusCode());

        $member = $this->s->loggedIn($this->s->member);
        $member->get('/account');
        $csrf = $member->csrfToken();
        self::assertSame(404, $member->get('/projects/' . $this->s->rivalProject->publicId . '/rankings/import')->getStatusCode());
        self::assertSame(404, $member->post('/projects/' . $this->s->rivalProject->publicId . '/rankings/import', ['_csrf' => $csrf])->getStatusCode());
    }
}

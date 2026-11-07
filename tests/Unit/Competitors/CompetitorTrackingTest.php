<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Competitors;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\KeywordComparison;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\Competitors\CompetitorTracker;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoSerp;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Modules\Rankings\RankChecker;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;

final class CompetitorTrackingTest extends TestCase
{
    public function testDomainRules(): void
    {
        self::assertSame('rival.test', Competitor::normalizeDomain('WWW.Rival.test.'));
        self::assertSame('shop.rival.test', Competitor::normalizeDomain('shop.rival.test'));
        self::assertTrue(Competitor::owns('rival.test', 'blog.rival.test'));
        self::assertTrue(Competitor::owns('rival.test', 'RIVAL.test'));
        self::assertFalse(Competitor::owns('rival.test', 'notrival.test'));
    }

    public function testGapsAndLead(): void
    {
        $c = static fn(?int $own, ?int $theirs): KeywordComparison => new KeywordComparison('id', 'kw', null, $own, $theirs, null);

        self::assertTrue($c(null, 4)->isGap(), 'They are on page one, you are not ranking.');
        self::assertTrue($c(15, 5)->isGap());
        self::assertFalse($c(12, 5)->isGap(), 'Less than 10 places behind.');
        self::assertFalse($c(null, 11)->isGap(), 'They are not on page one.');
        self::assertSame(-7, $c(12, 5)->lead());
        self::assertSame(96, $c(5, null)->lead());
        self::assertFalse($c(5, null)->bothRank());
    }

    public function testRankChecksRecordCompetitorPositionsWithoutExtraRequests(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-09-26 08:00:00', new \DateTimeZone('UTC')));
        $http = new FakeHttpClient();
        $dfs = new InMemoryDataForSeo($clock);
        $dfs->save(1, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, 1);
        $keywords = new InMemoryKeywords($clock);
        $rankings = new InMemoryRankings($keywords, $clock);
        $competitors = new InMemoryCompetitors($keywords, $rankings, $clock);
        $rankings->projects[10] = ['workspace_id' => 1, 'domain' => 'acme.test'];
        $rankings->saveSettings(10, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::DAILY), 1);
        $keywords->add(10, ['red shoes', 'blue shoes'], KeywordLocale::default(), 1);
        $rival = $competitors->add(10, 'rival.test', Competitor::MANUAL, 1);
        $other = $competitors->add(10, 'other.test', Competitor::MANUAL, 1);
        self::assertNotNull($rival);
        self::assertNotNull($other);

        // rival.test ranks 1st for "red shoes" and 40th (subdomain) for "blue shoes"; other.test never ranks.
        $http->on('POST', 'https://api.dataforseo.com' . DataForSeoSerp::PATH, static function (string $url, array $headers, ?string $body): HttpResponse {
            $keyword = json_decode((string) $body, true)[0]['keyword'];
            $items = $keyword === 'red shoes'
                ? [['type' => 'organic', 'rank_group' => 1, 'rank_absolute' => 1, 'domain' => 'www.rival.test', 'url' => 'https://www.rival.test/red']]
                : [['type' => 'organic', 'rank_group' => 40, 'rank_absolute' => 44, 'domain' => 'blog.rival.test', 'url' => 'https://blog.rival.test/blue']];
            $items[] = ['type' => 'organic', 'rank_group' => 3, 'rank_absolute' => 3, 'domain' => 'acme.test', 'url' => 'https://acme.test/'];

            return new HttpResponse(200, (string) json_encode(['status_code' => 20000, 'cost' => 0.002, 'tasks' => [['id' => 't', 'status_code' => 20000, 'result' => [['items' => $items]]]]]));
        });
        $client = new DataForSeoClient($http, $dfs, $dfs, $dfs, $clock, new NullLogger(), false, 7);
        $checker = new RankChecker($rankings, new DataForSeoSerp($client, $dfs, $clock), new NullLogger(), new CompetitorTracker($competitors));

        $checker->run(10, 1, '2026-09-26', static fn(): bool => true);

        self::assertCount(2, $http->requests, 'Competitors cost no additional requests.');
        $comparison = $competitors->compare(10, 1, $rival->id, SerpDevice::Desktop, '2026-09-26');
        self::assertSame([1, 40], array_map(static fn(KeywordComparison $c): ?int => $c->theirs, $comparison));
        self::assertSame('https://www.rival.test/red', $comparison[0]->theirUrl);
        $days = $competitors->days(10, SerpDevice::Desktop, '2026-09-26');
        self::assertSame(2, $days[$rival->id]->ranked);
        self::assertSame(1, $days[$rival->id]->top3);
        self::assertSame(0, $days[$other->id]->ranked);
        self::assertSame(2, $days[$other->id]->keywords, 'Not ranking is recorded, not missing.');
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Bing;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Modules\Bing\Api\BingWebmasterClient;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

/**
 * The Bing Webmaster API adapter against responses in Bing's documented format.
 */
final class BingWebmasterClientTest extends TestCase
{
    private const string KEY = 'abcdef0123456789abcdef0123456789';

    private FakeHttpClient $http;

    private BingWebmasterClient $client;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->client = new BingWebmasterClient($this->http);
    }

    public function testReadsWcfDatesAsPacificDays(): void
    {
        self::assertSame('2011-09-16', BingWebmasterClient::date('/Date(1316156400000-0700)/'));
        self::assertSame('2014-05-03', BingWebmasterClient::date('/Date(1399100400000)/'));
        self::assertNull(BingWebmasterClient::date('2014-05-03'));
        self::assertNull(BingWebmasterClient::date(null));
    }

    public function testSitesAreTheVerifiedOnesSorted(): void
    {
        $this->http->on('GET', BingWebmasterClient::BASE . 'GetUserSites', static fn(): HttpResponse => new HttpResponse(200, '{"d":[
            {"__type":"Site:#Microsoft.Bing.Webmaster.Api","IsVerified":true,"Url":"https://www.shop.test/"},
            {"__type":"Site:#Microsoft.Bing.Webmaster.Api","IsVerified":false,"Url":"https://unverified.test/"},
            {"__type":"Site:#Microsoft.Bing.Webmaster.Api","IsVerified":true,"Url":"https://blog.shop.test/"}]}'));

        self::assertSame(['https://blog.shop.test/', 'https://www.shop.test/'], $this->client->sites(self::KEY));
        $url = $this->http->requests[0]['url'];
        self::assertStringStartsWith('https://ssl.bing.com/webmaster/api.svc/json/GetUserSites?apikey=', $url);
    }

    public function testDailyTotalsAreSummedPerDay(): void
    {
        $this->http->on('GET', BingWebmasterClient::BASE . 'GetRankAndTrafficStats', static fn(): HttpResponse => new HttpResponse(200, '{"d":[
            {"__type":"RankAndTrafficStats:#Microsoft.Bing.Webmaster.Api","Clicks":15,"Date":"\/Date(1316156400000-0700)\/","Impressions":100},
            {"__type":"RankAndTrafficStats:#Microsoft.Bing.Webmaster.Api","Clicks":2,"Date":"\/Date(1316156400000-0700)\/","Impressions":10},
            {"__type":"RankAndTrafficStats:#Microsoft.Bing.Webmaster.Api","Clicks":4,"Date":"\/Date(1316070000000-0700)\/","Impressions":50}]}'));

        $days = $this->client->daily(self::KEY, 'https://www.shop.test/');

        self::assertSame(['2011-09-15', '2011-09-16'], [$days[0]->date, $days[1]->date]);
        self::assertSame(17, $days[1]->metrics->clicks);
        self::assertSame(110, $days[1]->metrics->impressions);
        self::assertStringContainsString('siteUrl=https%3A%2F%2Fwww.shop.test%2F', $this->http->requests[0]['url']);
    }

    public function testWeeklyRowsAreMergedAndWeightedByImpressions(): void
    {
        $this->http->on('GET', BingWebmasterClient::BASE . 'GetQueryStats', static fn(): HttpResponse => new HttpResponse(200, '{"d":[
            {"__type":"QueryStats:#Microsoft.Bing.Webmaster.Api","AvgClickPosition":3,"AvgImpressionPosition":2,"Clicks":5,"Date":"\/Date(1399100400000)\/","Impressions":100,"Query":"red shoes"},
            {"__type":"QueryStats:#Microsoft.Bing.Webmaster.Api","AvgClickPosition":-1,"AvgImpressionPosition":5,"Clicks":1,"Date":"\/Date(1399100400000)\/","Impressions":300,"Query":"red shoes"},
            {"__type":"QueryStats:#Microsoft.Bing.Webmaster.Api","AvgClickPosition":-1,"AvgImpressionPosition":-1,"Clicks":0,"Date":"\/Date(1399100400000)\/","Impressions":0,"Query":"  "}]}'));

        $rows = $this->client->weekly(self::KEY, 'https://www.shop.test/', PerformanceDimension::Query);

        self::assertCount(1, $rows);
        self::assertSame('2014-05-03', $rows[0]->weekEnd);
        self::assertSame('red shoes', $rows[0]->row->key);
        self::assertSame(6, $rows[0]->row->metrics->clicks);
        self::assertEqualsWithDelta(4.25, $rows[0]->row->metrics->position, 0.001);
    }

    public function testCrawlStatsCombineRedirectsAndKeepOneRowPerDay(): void
    {
        $this->http->on('GET', BingWebmasterClient::BASE . 'GetCrawlStats', static fn(): HttpResponse => new HttpResponse(200, '{"d":[
            {"__type":"CrawlStats:#Microsoft.Bing.Webmaster.Api","AllOtherCodes":1,"BlockedByRobotsTxt":2,"Code2xx":90,"Code301":4,"Code302":1,"Code4xx":3,"Code5xx":0,"ContainsMalware":0,"CrawlErrors":3,"CrawledPages":101,"Date":"\/Date(1316156400000-0700)\/","InIndex":250,"InLinks":40},
            {"__type":"CrawlStats:#Microsoft.Bing.Webmaster.Api","CrawledPages":80,"CrawlErrors":0,"InIndex":245,"Date":"\/Date(1316070000000-0700)\/"},
            {"__type":"CrawlStats:#Microsoft.Bing.Webmaster.Api","CrawledPages":5,"Date":"not a date"}]}'));

        $days = $this->client->crawlStats(self::KEY, 'https://www.shop.test/');

        self::assertSame(['2011-09-15', '2011-09-16'], [$days[0]->date, $days[1]->date]);
        self::assertSame([101, 3, 250, 40, 90, 5, 3, 0, 2, 1], [$days[1]->crawledPages, $days[1]->crawlErrors, $days[1]->inIndex, $days[1]->inLinks, $days[1]->code2xx, $days[1]->code3xx, $days[1]->code4xx, $days[1]->code5xx, $days[1]->blockedByRobots, $days[1]->otherCodes]);
        self::assertSame(0, $days[0]->code2xx, 'Missing counts read as zero.');
    }

    public function testLinkCountsFollowPagesUpToTheLimitMostLinkedFirst(): void
    {
        $pages = [];
        $this->http->on('GET', BingWebmasterClient::BASE . 'GetLinkCounts', static function (string $url) use (&$pages): HttpResponse {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $page = (int) $query['page'];
            $pages[] = $page;

            return new HttpResponse(200, json_encode(['d' => ['__type' => 'LinkCounts:#Microsoft.Bing.Webmaster.Api', 'TotalPages' => 9, 'Links' => [
                ['Count' => 10 + $page, 'Url' => 'https://www.shop.test/p' . $page],
                ['Count' => 2, 'Url' => 'https://www.shop.test/'],
                ['Count' => 99, 'Url' => 'mailto:x@shop.test'],
            ]]], JSON_THROW_ON_ERROR));
        });

        $links = $this->client->linkCounts(self::KEY, 'https://www.shop.test/', 3);

        self::assertSame([0, 1, 2], $pages, 'Stops at the page limit although Bing has more.');
        self::assertSame(['https://www.shop.test/p2', 'https://www.shop.test/p1', 'https://www.shop.test/p0', 'https://www.shop.test/'], array_map(static fn($l): string => $l->url, $links));
        self::assertSame(12, $links[0]->links);
    }

    public function testLinkCountsStopOnTheLastPage(): void
    {
        $this->http->json('GET', BingWebmasterClient::BASE . 'GetLinkCounts', ['d' => ['TotalPages' => 1, 'Links' => [['Count' => 3, 'Url' => 'https://www.shop.test/a']]]]);

        self::assertCount(1, $this->client->linkCounts(self::KEY, 'https://www.shop.test/'));
        self::assertCount(1, $this->http->requests);
    }

    public function testRejectedKeyLosesAuthorizationAndServerErrorsAreRetryable(): void
    {
        $this->http->on('GET', BingWebmasterClient::BASE . 'GetUserSites', static fn(): HttpResponse => new HttpResponse(400, '{"ErrorCode":3,"Message":"InvalidApiKey"}'));
        try {
            $this->client->sites(self::KEY);
            self::fail('Expected an exception.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->authorizationLost);
            self::assertFalse($e->retryable);
            self::assertStringNotContainsString(self::KEY, $e->getMessage());
        }

        $this->http->on('GET', BingWebmasterClient::BASE . 'GetUserSites', static fn(): HttpResponse => new HttpResponse(503, ''));
        try {
            $this->client->sites(self::KEY);
            self::fail('Expected an exception.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertFalse($e->authorizationLost);
        }
    }
}

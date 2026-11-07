<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SearchConsole;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleSearchConsoleApi;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleTokenSet;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Scenario;

final class GoogleQueryPagesTest extends TestCase
{
    public function testJointImportPaginatesGoogleResultsAndKeepsTheActualQueryUrlPair(): void
    {
        $s = new Scenario();
        $tokens = $s->container->make(GoogleAccessTokens::class);
        $connection = $s->container->make(InMemoryIntegrations::class)->upsert($s->acme->id, 'google', 'google@acme.test', '', $s->owner->id);
        $tokens->store($connection->id, new GoogleTokenSet('test-access', 'test-refresh', 3600, ''));
        $http = new FakeHttpClient();
        $http->on('POST', GoogleSearchConsoleApi::BASE . '/sites/sc-domain%3Aacme.test/searchAnalytics/query', static function (string $url, array $headers, ?string $body): HttpResponse {
            $request = json_decode((string) $body, true);
            self::assertSame(['query', 'page'], $request['dimensions']);
            self::assertSame('web', $request['type']);
            self::assertSame('final', $request['dataState']);
            $row = ['keys' => ['seo tools', $request['startRow'] === 0 ? 'https://acme.test/A?x=1' : 'https://acme.test/b'], 'clicks' => 2, 'impressions' => 100, 'position' => 5.5];
            $rows = $request['startRow'] === 0 ? array_fill(0, GoogleSearchConsoleApi::PAGE_SIZE, $row) : [$row, ['keys' => ['missing page'], 'clicks' => 1, 'impressions' => 1, 'position' => 1]];
            return new HttpResponse(200, json_encode(['rows' => $rows], JSON_THROW_ON_ERROR));
        });
        $api = new GoogleSearchConsoleApi($http, $tokens);
        $rows = $api->dayQueryPages($connection, 'sc-domain:acme.test', '2026-10-01', 25010);
        self::assertCount(25001, $rows);
        self::assertCount(2, $http->requests);
        self::assertSame('https://acme.test/A?x=1', $rows[0]->url);
        self::assertSame('https://acme.test/b', $rows[25000]->url);
        self::assertSame('seo tools', $rows[25000]->query);
        self::assertSame(10, json_decode((string) $http->requests[1]['body'], true)['rowLimit']);
    }
}

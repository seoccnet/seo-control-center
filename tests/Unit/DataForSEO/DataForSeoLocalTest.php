<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\DataForSEO;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoLocal;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;

/**
 * DataForSEO Google Maps and Business Profile responses normalized.
 */
final class DataForSeoLocalTest extends TestCase
{
    private const int WORKSPACE = 3;
    private const string API = 'https://api.dataforseo.com';

    private FakeHttpClient $http;

    private InMemoryDataForSeo $store;

    private DataForSeoLocal $local;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-03 12:00:00', new \DateTimeZone('UTC')));
        $this->store = new InMemoryDataForSeo($clock);
        $this->store->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, 1);
        $client = new DataForSeoClient($this->http, $this->store, $this->store, $this->store, $clock, new NullLogger(), false, 7);
        $this->local = new DataForSeoLocal($client, $this->store);
    }

    public function testMissingProfileItemsCannotClearTheSavedProfile(): void
    {
        $this->http->json('POST', self::API . DataForSeoLocal::PROFILE, ['status_code' => 20000, 'cost' => 0.0054, 'tasks' => [['status_code' => 20000, 'result' => [[]]]]]);
        $this->expectException(\SeoControlCenter\Domain\Integrations\IntegrationException::class);
        $this->local->profile(self::WORKSPACE, null, 'Shop', new LocalArea(1, 1), 'en');
    }

    public function testNonFiniteRatingsCannotReachLocalStorage(): void
    {
        foreach ([DataForSeoLocal::MAPS, DataForSeoLocal::PROFILE] as $path) {
            foreach (['1e400', '-1e400'] as $value) {
                $body = '{"status_code":20000,"cost":0.0054,"tasks":[{"status_code":20000,"result":[{"items":[{"type":"maps_search","title":"Shop","rank_group":1,"rating":{"value":' . $value . '}}]}]}]}';
                $this->http->on('POST', self::API . $path, static fn(): \SeoControlCenter\Core\Http\Client\HttpResponse => new \SeoControlCenter\Core\Http\Client\HttpResponse(200, $body));
                try {
                    if ($path === DataForSeoLocal::MAPS) {
                        $this->local->maps(self::WORKSPACE, null, 'shop', new LocalArea(1, 1), 'en', 20);
                    } else {
                        $this->local->profile(self::WORKSPACE, null, 'Shop', new LocalArea(1, 1), 'en');
                    }
                    self::fail('Non-finite ratings must fail before JSON persistence can trigger a paid retry.');
                } catch (IntegrationException $e) {
                    self::assertFalse($e->retryable);
                }
            }
        }
    }

    public function testMapsBusinessDomainUsesTheWebsiteUrlHost(): void
    {
        $items = [
            ['type' => 'maps_search', 'rank_group' => 1, 'title' => 'Rival', 'domain' => 'shop.test', 'url' => 'https://rival.test/'],
            ['type' => 'maps_search', 'rank_group' => 2, 'title' => 'Own shop', 'domain' => 'rival.test', 'url' => 'https://WWW.SHOP.TEST/page'],
            ['type' => 'maps_search', 'rank_group' => 3, 'title' => 'Other', 'domain' => 'shop.test', 'url' => 'javascript:alert(1)'],
            ['type' => 'maps_search', 'rank_group' => 4, 'title' => 'Domain only', 'domain' => ' SHOP.TEST '],
        ];
        $this->http->json('POST', self::API . DataForSeoLocal::MAPS, ['status_code' => 20000, 'cost' => 0.002, 'tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]]);
        $rows = $this->local->maps(self::WORKSPACE, null, 'shop', new LocalArea(1, 1), 'en', 20);
        self::assertFalse($rows[0]->isBusiness(null, 'shop.test', 'Shop'));
        self::assertSame('rival.test', $rows[0]->domain);
        self::assertTrue($rows[1]->isBusiness(null, 'shop.test', 'Shop'));
        self::assertFalse($rows[2]->isBusiness(null, 'shop.test', 'Shop'));
        self::assertNull($rows[2]->url);
        self::assertTrue($rows[3]->isBusiness(null, 'shop.test', 'Shop'));
    }

    public function testMapsResults(): void
    {
        $this->http->json('POST', self::API . DataForSeoLocal::MAPS, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.002, 'tasks' => [[
            'id' => 't', 'status_code' => 20000, 'result' => [['items' => [
                ['type' => 'maps_search', 'rank_group' => 2, 'title' => 'Acme Seeds', 'domain' => 'Acme.test', 'url' => 'https://acme.test/', 'rating' => ['value' => 4.7, 'votes_count' => 128], 'category' => 'Garden center', 'cid' => '111'],
                ['type' => 'maps_search', 'rank_group' => 1, 'title' => 'Rival Garden', 'rating' => ['value' => 4, 'votes_count' => 9]],
                ['type' => 'maps_paid_item', 'title' => 'Ad'],
            ]]],
        ]]]);

        $results = $this->local->maps(self::WORKSPACE, 7, 'seed shop', new LocalArea(43.8563, 18.4131, 14), 'bs', 20);

        self::assertSame(['Rival Garden', 'Acme Seeds'], array_map(static fn($r): string => $r->title, $results));
        self::assertSame(4.0, $results[0]->rating);
        self::assertSame(['acme.test', 4.7, 128, 'Garden center', '111'], [$results[1]->domain, $results[1]->rating, $results[1]->votes, $results[1]->category, $results[1]->cid]);
        $body = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertSame(['seed shop', '43.8563,18.4131,14z', 'bs', 20], [$body['keyword'], $body['location_coordinate'], $body['language_code'], $body['depth']]);
        self::assertEqualsWithDelta(0.002, $this->store->usage[1]['estimated'], 0.000001);
    }

    public function testMalformedMapsRowsCannotBeStoredAsMissingBusinessRankings(): void
    {
        foreach ([[], ['type' => ''], ['type' => 'maps_search'], ['type' => 'maps_search', 'title' => ''], ['type' => 'maps_search', 'title' => '   '], ['type' => 'maps_search', 'title' => []]] as $row) {
            $items = [['type' => 'maps_search', 'rank_group' => 1, 'title' => 'Valid rival', 'cid' => '222'], $row];
            $this->http->json('POST', self::API . DataForSeoLocal::MAPS, ['status_code' => 20000, 'cost' => 0.002, 'tasks' => [['status_code' => 20000, 'result' => [['items' => $items]]]]]);
            try {
                $this->local->maps(self::WORKSPACE, null, 'shop', new LocalArea(1, 1), 'en', 20);
                self::fail('Malformed Maps rows must not become a partial business ranking sample.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable);
            }
        }
    }

    public function testMissingMapsRankIsNotInventedFromRowOrder(): void
    {
        $this->http->json('POST', self::API . DataForSeoLocal::MAPS, ['status_code' => 20000, 'cost' => 0.002, 'tasks' => [['status_code' => 20000, 'result' => [['items' => [['type' => 'maps_search', 'title' => 'Shop', 'cid' => '111']]]]]]]);
        $this->expectException(\SeoControlCenter\Domain\Integrations\IntegrationException::class);
        $this->expectExceptionMessage('invalid Maps ranking');
        $this->local->maps(self::WORKSPACE, null, 'shop', new LocalArea(1, 1), 'en', 20);
    }

    public function testMalformedBusinessProfileIsNotReportedAsAbsent(): void
    {
        $this->http->json('POST', self::API . DataForSeoLocal::PROFILE, ['status_code' => 20000, 'cost' => 0.0054, 'tasks' => [['status_code' => 20000, 'result' => [['items' => [['type' => 'google_business_info', 'cid' => '111']]]]]]]);
        $this->expectException(\SeoControlCenter\Domain\Integrations\IntegrationException::class);
        $this->expectExceptionMessage('invalid business profile');
        $this->local->profile(self::WORKSPACE, null, 'cid:111', new LocalArea(1, 1), 'en');
    }

    public function testProfileForAnotherCidCannotOverwriteTheRequestedBusiness(): void
    {
        $this->http->json('POST', self::API . DataForSeoLocal::PROFILE, ['status_code' => 20000, 'cost' => 0.0054, 'tasks' => [['status_code' => 20000, 'result' => [['items' => [['title' => 'Another Shop', 'cid' => '222']]]]]]]);
        try {
            $this->local->profile(self::WORKSPACE, 7, 'cid:111', new LocalArea(1, 1), 'en');
            self::fail('A different business must not be recorded as the requested profile.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable);
        }
        self::assertCount(1, $this->http->requests);
        self::assertSame('charged', $this->store->usage[1]['status']);
    }

    public function testBusinessProfileCountsMustFitUnsignedHistoryColumns(): void
    {
        foreach (['votes_count', 'total_photos'] as $field) {
            foreach ([null, 0, 4294967295, -1, 4294967296] as $value) {
                $item = ['title' => 'Shop'];
                if ($field === 'votes_count') {
                    $item['rating'] = ['votes_count' => $value];
                } else {
                    $item[$field] = $value;
                }
                $this->http->json('POST', self::API . DataForSeoLocal::PROFILE, ['status_code' => 20000, 'cost' => 0.0054, 'tasks' => [['status_code' => 20000, 'result' => [['items' => [$item]]]]]]);
                try {
                    $profile = $this->local->profile(self::WORKSPACE, null, 'Shop', new LocalArea(1, 1), 'en');
                    self::assertNotNull($profile);
                    self::assertSame($value, $field === 'votes_count' ? $profile->votes : $profile->photos);
                    self::assertTrue($value === null || ($value >= 0 && $value <= 4294967295), 'Invalid counts must fail before persistence.');
                } catch (IntegrationException $e) {
                    self::assertTrue($value === -1 || $value === 4294967296);
                    self::assertFalse($e->retryable);
                }
            }
        }
    }

    public function testBusinessProfile(): void
    {
        $this->http->json('POST', self::API . DataForSeoLocal::PROFILE, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.0054, 'tasks' => [[
            'id' => 't', 'status_code' => 20000, 'result' => [['items' => [[
                'type' => 'google_business_info', 'title' => 'Acme Seeds', 'category' => 'Garden center', 'rating' => ['value' => 4.7, 'votes_count' => 128],
                'is_claimed' => false, 'address' => 'Main St 1, Springfield', 'phone' => '+44 33 000', 'url' => 'https://acme.test/', 'total_photos' => 31, 'cid' => '111',
            ]]]],
        ]]]);

        $profile = $this->local->profile(self::WORKSPACE, 7, 'cid:111', new LocalArea(43.8563, 18.4131, 13), 'en');

        self::assertNotNull($profile);
        self::assertSame(['Acme Seeds', 4.7, 128, false, 31, '111'], [$profile->title, $profile->rating, $profile->votes, $profile->claimed, $profile->photos, $profile->cid]);
        $body = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertSame(['cid:111', '43.8563,18.4131,10000'], [$body['keyword'], $body['location_coordinate']]);

        $this->http->json('POST', self::API . DataForSeoLocal::PROFILE, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.0054, 'tasks' => [['id' => 't', 'status_code' => 20000, 'result' => [['items' => null]]]]]);
        self::assertNull($this->local->profile(self::WORKSPACE, 7, 'Nobody', new LocalArea(1, 1), 'en'));
        self::assertEqualsWithDelta(0.0094, $this->local->estimatedCost(2), 0.000001);
    }

    public function testNoSearchResultsMeansNoProfile(): void
    {
        $this->http->json('POST', self::API . DataForSeoLocal::PROFILE, ['status_code' => 20000, 'cost' => 0, 'tasks' => [['status_code' => 40102, 'status_message' => 'No Search Results.', 'cost' => 0]]]);
        self::assertNull($this->local->profile(self::WORKSPACE, 7, 'Northwind Goods', new LocalArea(1, 1), 'en'), 'finding 42: Google Maps has no business by that name');

        $this->http->json('POST', self::API . DataForSeoLocal::PROFILE, ['status_code' => 20000, 'cost' => 0, 'tasks' => [['status_code' => 40501, 'status_message' => 'Invalid Field.', 'cost' => 0]]]);
        $this->expectException(IntegrationException::class);
        $this->local->profile(self::WORKSPACE, 7, 'Northwind Goods', new LocalArea(1, 1), 'en');
    }
}

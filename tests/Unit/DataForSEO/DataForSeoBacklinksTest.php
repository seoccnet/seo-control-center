<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\DataForSEO;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoBacklinks;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;

/**
 * DataForSEO Backlinks responses normalized into profiles, referring domains and anchors.
 */
final class DataForSeoBacklinksTest extends TestCase
{
    private const int WORKSPACE = 3;
    private const string API = 'https://api.dataforseo.com';

    private FakeHttpClient $http;

    private InMemoryDataForSeo $store;

    private DataForSeoBacklinks $backlinks;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-03 12:00:00', new \DateTimeZone('UTC')));
        $this->store = new InMemoryDataForSeo($clock);
        $this->store->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'secret'), 10.0, 1);
        $client = new DataForSeoClient($this->http, $this->store, $this->store, $this->store, $clock, new NullLogger(), false, 7);
        $this->backlinks = new DataForSeoBacklinks($client, $this->store);
    }

    public function testCreatedTaskCannotBecomeACompletedBacklinkSummary(): void
    {
        $this->http->json('POST', self::API . DataForSeoBacklinks::SUMMARY, ['status_code' => 20000, 'cost' => 0.024, 'tasks' => [['status_code' => 20100, 'result' => [['backlinks' => 0]]]]]);
        try {
            $this->backlinks->profiles(self::WORKSPACE, null, ['shop.test']);
            self::fail('An unfinished summary must not replace the saved profile.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable);
        }
        self::assertCount(1, $this->http->requests);
    }

    public function testInvalidNumericCountsCannotBecomeBacklinkProfiles(): void
    {
        foreach (['1e400', '1e30', '-1', '-0.5', 'false', '[]', '"12"'] as $value) {
            $body = '{"status_code":20000,"cost":0.024,"tasks":[{"status_code":20000,"result":[{"backlinks":' . $value . '}]}]}';
            $this->http->on('POST', self::API . DataForSeoBacklinks::SUMMARY, static fn(): HttpResponse => new HttpResponse(200, $body));
            try {
                $this->backlinks->profiles(self::WORKSPACE, null, ['shop.test']);
                self::fail('Invalid counts must not overwrite the saved backlink profile.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable, 'A paid response must not be purchased again automatically.');
            }
        }
        $this->http->json('POST', self::API . DataForSeoBacklinks::SUMMARY, ['status_code' => 20000, 'cost' => 0.024, 'tasks' => [['status_code' => 20000, 'result' => [['rank' => 34.6, 'backlinks' => 0, 'referring_domains' => null]]]]]);
        $profile = $this->backlinks->profiles(self::WORKSPACE, null, ['shop.test'])[0];
        self::assertSame(35, $profile->rank);
        self::assertSame(0, $profile->backlinks);
        self::assertNull($profile->referringDomains);
    }

    public function testProfileMetricsMustFitHistoryStorage(): void
    {
        foreach ([['referring_domains', 4294967295, false], ['referring_domains', 4294967296, true], ['rank', 65535, false], ['rank', 65536, true]] as [$field, $value, $invalid]) {
            $this->http->json('POST', self::API . DataForSeoBacklinks::SUMMARY, ['status_code' => 20000, 'cost' => 0.024, 'tasks' => [['status_code' => 20000, 'result' => [[$field => $value]]]]]);
            try {
                $profiles = $this->backlinks->profiles(self::WORKSPACE, null, ['shop.test']);
                self::assertSame($value, $field === 'rank' ? $profiles[0]->rank : $profiles[0]->referringDomains);
                self::assertFalse($invalid, 'Overflow must fail before history persistence.');
            } catch (IntegrationException $e) {
                self::assertTrue($invalid);
                self::assertFalse($e->retryable);
            }
        }
    }

    public function testSummaryForAnotherTargetCannotOverwriteTheRequestedProfile(): void
    {
        foreach (['rival.test', 'www.shop.test', null, []] as $target) {
            $this->http->json('POST', self::API . DataForSeoBacklinks::SUMMARY, ['status_code' => 20000, 'cost' => 0.024, 'tasks' => [['status_code' => 20000, 'result' => [['target' => $target, 'backlinks' => 999]]]]]);
            try {
                $this->backlinks->profiles(self::WORKSPACE, null, ['shop.test']);
                self::fail('Metrics for another target must not be saved under the requested domain.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable);
            }
        }
        $this->http->json('POST', self::API . DataForSeoBacklinks::SUMMARY, ['status_code' => 20000, 'cost' => 0.024, 'tasks' => [['status_code' => 20000, 'result' => [['target' => 'SHOP.TEST', 'backlinks' => 0]]]]]);
        $profiles = $this->backlinks->profiles(self::WORKSPACE, null, ['shop.test']);
        self::assertSame('shop.test', $profiles[0]->domain);
        self::assertSame(0, $profiles[0]->backlinks);
    }

    public function testProfilesOnePerDomain(): void
    {
        $this->http->on('POST', self::API . DataForSeoBacklinks::SUMMARY, static function (string $url, array $headers, ?string $body): HttpResponse {
            $target = json_decode((string) $body, true)[0]['target'] ?? '';
            $task = $target === 'shop.acme.test'
                ? ['id' => 'a', 'status_code' => 20000, 'result' => [['target' => 'shop.acme.test', 'rank' => 34, 'backlinks' => 5210, 'referring_domains' => 412, 'referring_domains_nofollow' => 80, 'backlinks_spam_score' => 6, 'broken_backlinks' => 3, 'first_seen' => '2019-04-02 10:00:00 +00:00']]]
                : ['id' => 'b', 'status_code' => 40501, 'status_message' => 'Invalid Field: target.'];

            return new HttpResponse(200, (string) json_encode(['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.024, 'tasks' => [$task]]));
        });

        $profiles = $this->backlinks->profiles(self::WORKSPACE, 5, ['shop.acme.test', 'bad..test']);

        self::assertCount(1, $profiles, 'a rejected domain is left out');
        self::assertSame(['shop.acme.test', 34, 5210, 412, 80, 6, 3, '2019-04-02'], array_values((array) $profiles[0]));
        $body = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertSame(['live', 'one_hundred', true], [$body['backlinks_status_type'], $body['rank_scale'], $body['include_subdomains']]);
        self::assertCount(2, $this->http->requests);
        self::assertEqualsWithDelta(0.024036, $this->store->usage[1]['estimated'], 0.000001);
    }

    public function testPageProfilesTargetTheExactPage(): void
    {
        // ADR-041 G2: backlinks of single competitor pages for topic plans.
        $this->http->on('POST', self::API . DataForSeoBacklinks::SUMMARY, static function (string $url, array $headers, ?string $body): HttpResponse {
            $target = json_decode((string) $body, true)[0]['target'] ?? '';
            $task = $target === 'https://www.rival.test/blog/brandywine/'
                ? ['id' => 'a', 'status_code' => 20000, 'result' => [['target' => 'rival.test/blog/brandywine', 'rank' => 21, 'backlinks' => 420, 'referring_domains' => 48]]]
                : ['id' => 'b', 'status_code' => 40501, 'status_message' => 'Invalid Field: target.'];

            return new HttpResponse(200, (string) json_encode(['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.024, 'tasks' => [$task]]));
        });

        $profiles = $this->backlinks->pageProfiles(self::WORKSPACE, 5, ['https://www.rival.test/blog/brandywine/', 'https://bad.test/x', 'ftp://skip.test/y']);

        self::assertCount(1, $profiles, 'A rejected page is left out; a non-web address is never sent.');
        self::assertSame(['https://www.rival.test/blog/brandywine/', 21, 420, 48], [$profiles[0]->domain, $profiles[0]->rank, $profiles[0]->backlinks, $profiles[0]->referringDomains], 'The echo without scheme, www or slash is the same page.');
        $body = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertArrayNotHasKey('include_subdomains', $body);
        self::assertCount(2, $this->http->requests);

        $this->http->json('POST', self::API . DataForSeoBacklinks::SUMMARY, ['status_code' => 20000, 'cost' => 0.024, 'tasks' => [['status_code' => 20000, 'result' => [['target' => 'https://rival.test/blog/other', 'backlinks' => 9]]]]]);
        $this->expectException(IntegrationException::class);
        $this->backlinks->pageProfiles(self::WORKSPACE, 5, ['https://rival.test/blog/brandywine']);
    }

    public function testMissingBacklinkItemsDoNotBecomeEmptyResults(): void
    {
        $this->http->json('POST', self::API . DataForSeoBacklinks::REFERRING_DOMAINS, ['status_code' => 20000, 'cost' => 0.024, 'tasks' => [['status_code' => 20000, 'result' => [['items_count' => 10]]]]]);
        $this->expectException(\SeoControlCenter\Domain\Integrations\IntegrationException::class);
        $this->backlinks->referringDomains(self::WORKSPACE, null, 'shop.test', 10);
    }

    public function testReferringDomainsNewLostAndAnchors(): void
    {
        $this->http->json('POST', self::API . DataForSeoBacklinks::REFERRING_DOMAINS, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.03, 'tasks' => [[
            'id' => 't', 'status_code' => 20000, 'result' => [['items' => [
                ['type' => 'backlinks_referring_domain', 'domain' => 'Blog.Test', 'rank' => 40, 'backlinks' => 12, 'backlinks_spam_score' => 2, 'first_seen' => '2026-09-20 08:00:00 +00:00', 'lost_date' => null],
                ['domain' => ''],
            ]]],
        ]]]);
        $domains = $this->backlinks->referringDomains(self::WORKSPACE, null, 'shop.acme.test', 500);
        self::assertSame(['blog.test', 40, 12, 2, '2026-09-20', null, null], array_values((array) $domains[0]));
        self::assertCount(1, $domains);
        $body = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertSame([500, 'live', ['rank,desc']], [$body['limit'], $body['backlinks_status_type'], $body['order_by']]);
        self::assertArrayNotHasKey('filters', $body);

        $this->backlinks->referringDomains(self::WORKSPACE, null, 'shop.acme.test', 200, '2026-09-03', true);
        $lost = json_decode((string) $this->http->requests[1]['body'], true)[0];
        self::assertSame(['lost', ['lost_date', '>=', '2026-09-03 00:00:00 +00:00'], ['lost_date,desc']], [$lost['backlinks_status_type'], $lost['filters'], $lost['order_by']]);
        $this->backlinks->referringDomains(self::WORKSPACE, null, 'shop.acme.test', 200, '2026-09-03');
        self::assertSame(['first_seen', '>=', '2026-09-03 00:00:00 +00:00'], json_decode((string) $this->http->requests[2]['body'], true)[0]['filters']);

        $this->http->json('POST', self::API . DataForSeoBacklinks::ANCHORS, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.03, 'tasks' => [[
            'id' => 't', 'status_code' => 20000, 'result' => [['items' => [['anchor' => ' Acme Shop ', 'backlinks' => 300, 'referring_domains' => 90, 'first_seen' => '2020-01-01 00:00:00 +00:00'], ['anchor' => '', 'backlinks' => 12]]]],
        ]]]);
        $anchors = $this->backlinks->anchors(self::WORKSPACE, null, 'shop.acme.test', 100);
        self::assertSame(['Acme Shop', 300, 90, '2020-01-01'], array_values((array) $anchors[0]));
        self::assertSame('', $anchors[1]->anchor);
    }

    public function testPageLinksNormalizeTargetsAndReserveTheirBoundedCost(): void
    {
        $this->http->json('POST', self::API . DataForSeoBacklinks::LINKS, ['status_code' => 20000, 'cost' => 0.03, 'tasks' => [[
            'id' => 'links', 'status_code' => 20000, 'result' => [['items' => [
                ['url_from' => 'https://source.test/article', 'url_to' => 'https://shop.acme.test/page?x=1', 'anchor' => '<script>bad</script>', 'domain_from_rank' => 42, 'dofollow' => true, 'first_seen' => '2025-01-01 00:00:00 +00:00', 'last_seen' => '2026-10-01 00:00:00 +00:00'],
                ['url_from' => 'javascript:alert(1)', 'url_to' => 'https://shop.acme.test/'],
            ]]],
        ]]]);
        $rows = $this->backlinks->pageLinks(self::WORKSPACE, 5, 'shop.acme.test', 10000, '2026-09-03', true);
        self::assertCount(1, $rows);
        self::assertSame(['https://source.test/article', 'https://shop.acme.test/page?x=1', '<script>bad</script>', 42, true, '2025-01-01', '2026-10-01'], array_values($rows[0]->toArray()));
        $body = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertSame(1000, $body['limit']);
        self::assertSame('lost', $body['backlinks_status_type']);
        self::assertTrue($body['exclude_internal_backlinks']);
        // Live response 2026-10-10: "Invalid Field: 'order_by'" for lost_date; backlinks only have last_seen.
        self::assertSame(['last_seen', '>=', '2026-09-03 00:00:00 +00:00'], $body['filters']);
        self::assertSame(['last_seen,desc'], $body['order_by']);
        self::assertEqualsWithDelta(0.06, $this->store->usage[1]['estimated'], 0.000001);
    }

    public function testLinkGapCountOverflowStopsPaidRetry(): void
    {
        foreach ([0, 1] as $extra) {
            $this->http->json('POST', self::API . DataForSeoBacklinks::INTERSECTION, ['status_code' => 20000, 'cost' => 0.03, 'tasks' => [[
                'status_code' => 20000, 'result' => [['items' => [['domain_intersection' => [
                    '1' => ['target' => 'news.test', 'backlinks' => PHP_INT_MAX],
                    '2' => ['target' => 'news.test', 'backlinks' => $extra],
                ]]]]],
            ]]]);
            try {
                $rows = $this->backlinks->linkGap(self::WORKSPACE, null, 'shop.test', ['rival.test', 'other.test'], 10);
                self::assertSame(0, $extra, 'An overflowing aggregate must fail before DTO construction.');
                self::assertSame(PHP_INT_MAX, $rows[0]->backlinks);
            } catch (IntegrationException $e) {
                self::assertSame(1, $extra);
                self::assertFalse($e->retryable);
            }
        }
    }

    public function testLinkGap(): void
    {
        $this->http->json('POST', self::API . DataForSeoBacklinks::INTERSECTION, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0.03, 'tasks' => [[
            'id' => 't', 'status_code' => 20000, 'result' => [['items' => [
                ['domain_intersection' => ['1' => ['target' => 'news.test', 'rank' => 20, 'backlinks' => 3, 'first_seen' => '2025-01-01 00:00:00 +00:00']]],
                ['domain_intersection' => [
                    '1' => ['target' => 'Mag.Test', 'rank' => 55, 'backlinks' => 2, 'backlinks_spam_score' => 1, 'first_seen' => '2024-05-01 00:00:00 +00:00'],
                    '2' => ['target' => 'mag.test', 'rank' => 55, 'backlinks' => 4, 'first_seen' => '2023-02-01 00:00:00 +00:00'],
                ]],
                ['summary' => []],
            ]]],
        ]]]);

        $gap = $this->backlinks->linkGap(self::WORKSPACE, null, 'shop.acme.test', ['rival.test', 'other.test', 'shop.acme.test'], 300);

        self::assertSame(['mag.test', 'news.test'], array_map(static fn(ReferringDomain $d): string => $d->domain, $gap), 'linking to more competitors first');
        self::assertSame([2, 55, 6, 1, '2023-02-01'], [$gap[0]->targets, $gap[0]->rank, $gap[0]->backlinks, $gap[0]->spamScore, $gap[0]->firstSeen]);
        $body = json_decode((string) $this->http->requests[0]['body'], true)[0];
        self::assertSame([['1' => 'rival.test', '2' => 'other.test'], ['shop.acme.test']], [$body['targets'], $body['exclude_targets']]);
        self::assertSame([], $this->backlinks->linkGap(self::WORKSPACE, null, 'shop.acme.test', [], 300), 'no request without competitors');
        self::assertCount(1, $this->http->requests);
    }
}

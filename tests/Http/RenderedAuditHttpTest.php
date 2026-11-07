<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Modules\Pages\PageSummary;
use SeoControlCenter\Modules\Research\ResearchResult;
use SeoControlCenter\Modules\SiteAudit\RenderedAuditService;
use SeoControlCenter\Modules\SiteAudit\RenderedPageProvider;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Scenario;

final class RenderedAuditHttpTest extends TestCase
{
    private const string URL = 'https://shop.acme.test/js';
    private const string TASK = '10071234-1234-1234-1234-123456789abc';
    private Scenario $s;
    private FakeHttpClient $http;
    private InMemoryDataForSeo $data;
    private string $host;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->s = new Scenario([], $this->clock);
        $this->http = $this->s->container->make(FakeHttpClient::class);
        $this->data = $this->s->container->make(InMemoryDataForSeo::class);
        $this->data->save($this->s->acme->id, new DataForSeoCredentials('fake@example.test', 'fake-secret'), 10, $this->s->owner->id);
        $this->s->container->make(InMemoryPages::class)->pages[$this->s->acmeProject->id] = [new PageSummary(self::URL, null, null)];
        $this->host = 'https://' . $this->s->container->make(DataForSeoClient::class)->host();
        $this->http->on('GET', 'https://shop.acme.test/robots.txt', static fn(): HttpResponse => new HttpResponse(404, ''));
        $this->http->json('POST', $this->host . RenderedPageProvider::SUBMIT_PATH, ['status_code' => 20000, 'cost' => 0.0051,
            'tasks' => [['id' => self::TASK, 'status_code' => 20100, 'cost' => 0.0051, 'result' => null]]]);
        foreach (['resources', 'waterfall'] as $endpoint) {
            $items = $endpoint === 'resources' ? [['url' => 'https://shop.acme.test/app.css', 'status_code' => 404, 'resource_type' => 'stylesheet', 'fetch_timing' => ['duration_time' => 20]]] : [['page_url' => self::URL, 'resources' => [['url' => 'https://shop.acme.test/app.css', 'duration_time' => 20, 'fetch_start' => 10, 'fetch_end' => 30]]]];
            $this->http->json('POST', $this->host . '/v3/on_page/' . $endpoint, ['status_code' => 20000, 'cost' => 0, 'tasks' => [['id' => self::TASK, 'status_code' => 20000, 'result' => [['crawl_progress' => 'finished', 'total_items_count' => 1, 'items' => $items]]]]]);
        }
    }

    public function testSubmissionAndFreePollingSaveBoundedEscapedBrowserEvidence(): void
    {
        $this->request();
        $this->runCheck(); // robots preflight only
        self::assertCount(0, $this->data->usage);
        $this->runCheck(); // paid submission only
        self::assertCount(1, $this->data->usage);
        $this->poll(['crawl_progress' => 'in_progress']);
        $this->runCheck();
        self::assertTrue($this->storedResult()->isQueued());
        self::assertCount(1, $this->data->usage);
        $this->poll($this->finished());
        $this->runCheck();
        self::assertSame('done', $this->storedResult()->status);
        self::assertSame(2800.0, $this->storedResult()->items[0]['lcp_ms']);
        self::assertSame(0.12, $this->storedResult()->items[0]['cls']);
        self::assertSame(['https://shop.acme.test/dynamic'], $this->storedResult()->items[0]['links']);
        self::assertCount(100, $this->storedResult()->items[0]['resources']);
        $body = (string) $this->s->loggedIn($this->s->owner)->get($this->base())->getBody();
        self::assertStringContainsString('&lt;script&gt;unsafe', $body);
        self::assertStringNotContainsString('<script>unsafe', $body);
        self::assertStringContainsString('Lab LCP', $body);
        self::assertStringContainsString('INP is not supplied', $body);
        self::assertStringContainsString('css_background', $body);
        self::assertNull($this->storedResult()->items[0]['lazy_images']);
        self::assertStringContainsString('Unavailable in this saved snapshot', $body);
        self::assertNull($this->storedResult()->items[0]['responsive_images']);
        self::assertCount(1, $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
        $request = $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH)[0];
        $task = json_decode((string) $request['body'], true, flags: JSON_THROW_ON_ERROR)[0];
        self::assertSame(1, $task['max_crawl_pages']);
        self::assertTrue($task['enable_browser_rendering']);
        self::assertFalse($task['force_sitewide_checks']);
        self::assertSame('mobile', $task['browser_preset']);
        self::assertLessThanOrEqual(2000, strlen($task['custom_js']));
    }

    public function testUncertainSubmissionCannotBeAutomaticallyOrManuallyBoughtAgain(): void
    {
        $this->http->on('POST', $this->host . RenderedPageProvider::SUBMIT_PATH, static fn(): never => throw new HttpClientException('Timeout.', true));
        $this->request();
        $this->runCheck();
        $this->runCheck();
        self::assertSame('failed', $this->storedResult()->status);
        self::assertSame('submission', $this->storedResult()->params['_batch']['pending']);
        $this->runCheck();
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base());
        self::assertSame(409, $owner->post($this->base() . '/render', ['url' => self::URL])->getStatusCode());
        $owner->get($this->base());
        self::assertSame(409, $owner->post($this->base() . '/render', ['url' => self::URL, 'fresh' => '1'])->getStatusCode());
        self::assertCount(1, $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
    }

    public function testAfterTheReviewWindowANewCheckStartsOverInsteadOfResumingTheUncertainStep(): void
    {
        // Live 0.10.2: the 24-hour window let the request through, but the retry copied the old
        // progress, hit its uncertain marker and stopped without making any request.
        $this->http->on('POST', $this->host . RenderedPageProvider::SUBMIT_PATH, static fn(): never => throw new HttpClientException('Timeout.', true));
        $this->request();
        $this->runCheck();
        $this->runCheck();
        self::assertSame('failed', $this->storedResult()->status);
        self::assertSame('submission', $this->storedResult()->params['_batch']['pending']);

        $this->clock->advance('+25 hours');
        $this->http->json('POST', $this->host . RenderedPageProvider::SUBMIT_PATH, ['status_code' => 20000, 'cost' => 0.0051,
            'tasks' => [['id' => self::TASK, 'status_code' => 20100, 'cost' => 0.0051, 'result' => null]]]);
        $this->request();
        self::assertArrayNotHasKey('_batch', $this->storedResult()->params);
        $this->runCheck(); // refreshes the 25-hour-old robots.txt evidence first
        $this->runCheck();

        self::assertCount(2, $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
        self::assertNull($this->storedResult()->params['_batch']['pending'] ?? null);
    }

    public function testLazyImageCandidatesAreSeparateBoundedAndEscaped(): void
    {
        $this->request();
        $this->runCheck();
        $this->runCheck();
        $result = $this->finished();
        $result['items'][0]['custom_js_response']['lazy_images'] = [
            '/lazy.png', '/lazy.png', 'javascript:alert(1)', '/image?name=<script>',
            ...array_fill(0, 36, '/other.png'), '/outside-sample.png',
        ];
        $this->poll($result);
        $this->runCheck();
        self::assertSame(['https://shop.acme.test/lazy.png', 'https://shop.acme.test/image?name=<script>', 'https://shop.acme.test/other.png'], $this->storedResult()->items[0]['lazy_images']);
        self::assertCount(100, $this->storedResult()->items[0]['resources']);
        $body = (string) $this->s->loggedIn($this->s->owner)->get($this->base())->getBody();
        self::assertStringContainsString('Lazy-image candidates (3)', $body);
        self::assertStringContainsString('image?name=&lt;script&gt;', $body);
        self::assertStringNotContainsString('/outside-sample.png', $body);
        self::assertCount(1, $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
    }

    public function testMalformedOptionalLazyEvidenceRemainsUnavailable(): void
    {
        $provider = $this->s->container->make(RenderedPageProvider::class);
        foreach (['invalid', ['unexpected' => '/image.png'], null] as $invalid) {
            $result = $this->finished();
            $result['items'][0]['custom_js_response']['lazy_images'] = $invalid;
            $this->poll($result);
            $page = $provider->result($this->s->acme->id, self::TASK, self::URL);
            self::assertNotNull($page);
            self::assertArrayHasKey('lazy_images', $page);
            self::assertNull($page['lazy_images']);
        }
        $result['items'][0]['custom_js_response']['lazy_images'] = [null, 123, 'data:image/png;base64,AAA'];
        $this->poll($result);
        $page = $provider->result($this->s->acme->id, self::TASK, self::URL);
        self::assertNotNull($page);
        self::assertArrayHasKey('lazy_images', $page);
        self::assertSame([], $page['lazy_images']);
    }

    public function testPseudoElementReferencesRetainKindsWithoutBecomingBrokenFindings(): void
    {
        $this->request();
        $this->runCheck();
        $this->runCheck();
        $result = $this->finished();
        $result['items'][0]['custom_js_response']['resources'] = [
            ['url' => '/before(1).png', 'kind' => 'css_before_background'],
            ['url' => '/before(1).png', 'kind' => 'css_before_background'],
            ['url' => '/image?name=<script>', 'kind' => 'css_after_background'],
            ['url' => 'javascript:alert(1)', 'kind' => 'css_after_background'],
            ['url' => '/unsupported.png', 'kind' => 'unknown'],
        ];
        $this->poll($result);
        $this->runCheck();
        $row = $this->storedResult()->items[0];
        self::assertSame([
            ['url' => 'https://shop.acme.test/before(1).png', 'kind' => 'css_before_background'],
            ['url' => 'https://shop.acme.test/image?name=<script>', 'kind' => 'css_after_background'],
        ], $row['resources']);
        self::assertSame([], $row['findings']);
        $body = (string) $this->s->loggedIn($this->s->owner)->get($this->base())->getBody();
        self::assertStringContainsString('css_before_background', $body);
        self::assertStringContainsString('css_after_background', $body);
        self::assertStringContainsString('image?name=&lt;script&gt;', $body);
        self::assertStringNotContainsString('/unsupported.png', $body);
        self::assertCount(1, $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
    }

    public function testResponsiveCandidatesAreFilteredDeduplicatedBoundedAndEscaped(): void
    {
        $this->request();
        $this->runCheck();
        $this->runCheck();
        $result = $this->finished();
        $result['items'][0]['custom_js_response']['srcsets'] = [
            '/image?crop=1,2 1x, /image?name=<script> 2x',
            '/image?crop=1,2 640w, data:image/png;base64,AAA 2x, javascript:alert(1) 3x',
            ...array_fill(0, 18, '/other.png 1x'), '/outside-sample.png 1x',
        ];
        $this->poll($result);
        $this->runCheck();
        self::assertSame(['https://shop.acme.test/image?crop=1,2', 'https://shop.acme.test/image?name=<script>', 'https://shop.acme.test/other.png'], $this->storedResult()->items[0]['responsive_images']);
        $body = (string) $this->s->loggedIn($this->s->owner)->get($this->base())->getBody();
        self::assertStringContainsString('Responsive-image candidates (3)', $body);
        self::assertStringContainsString('image?name=&lt;script&gt;', $body);
        self::assertStringNotContainsString('/outside-sample.png', $body);
        self::assertCount(1, $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));

        $result['items'][0]['custom_js_response']['srcsets'] = [implode(', ', array_map(static fn(int $i): string => '/candidate-' . $i . '.png 1x', range(1, 60)))];
        $this->poll($result);
        $page = $this->s->container->make(RenderedPageProvider::class)->result($this->s->acme->id, self::TASK, self::URL);
        self::assertNotNull($page);
        self::assertArrayHasKey('responsive_images', $page);
        self::assertIsArray($page['responsive_images']);
        self::assertCount(40, $page['responsive_images']);
    }

    public function testBrowserDocumentBaseResolvesDeclarationsWithoutChangingPageIdentity(): void
    {
        $this->request();
        $this->runCheck();
        $this->runCheck();
        $result = $this->finished();
        $result['items'][0]['custom_js_response']['base_url'] = 'https://cdn.acme.test/assets/';
        $result['items'][0]['custom_js_response']['lazy_images'] = ['lazy.png'];
        $result['items'][0]['custom_js_response']['srcsets'] = ['small.png 1x, /large.png 2x'];
        $result['items'][0]['custom_js_response']['resources'] = [['url' => 'bg.png', 'kind' => 'css_background']];
        $result['items'][0]['custom_js_response']['links'] = ['relative', 'https://shop.acme.test/absolute'];
        $this->poll($result);
        $this->runCheck();
        $row = $this->storedResult()->items[0];
        self::assertSame(self::URL, $row['url']);
        self::assertSame('https://cdn.acme.test/assets/', $row['reference_base_url']);
        self::assertSame(['https://cdn.acme.test/assets/lazy.png'], $row['lazy_images']);
        self::assertSame(['https://cdn.acme.test/assets/small.png', 'https://cdn.acme.test/large.png'], $row['responsive_images']);
        self::assertSame([['url' => 'https://cdn.acme.test/assets/bg.png', 'kind' => 'css_background']], $row['resources']);
        self::assertSame(['https://cdn.acme.test/assets/relative', 'https://shop.acme.test/absolute'], $row['links']);
        $body = (string) $this->s->loggedIn($this->s->owner)->get($this->base())->getBody();
        self::assertStringContainsString('Reference base URL', $body);
        self::assertStringContainsString('https://cdn.acme.test/assets/', $body);
        self::assertCount(0, $this->http->requestsTo('https://cdn.acme.test/assets/lazy.png'));
    }

    public function testInvalidDocumentBaseOmitsRelativeReferencesButRetainsAbsoluteOnes(): void
    {
        $provider = $this->s->container->make(RenderedPageProvider::class);
        foreach ([null, 'javascript:alert(1)', 'https://user:password@cdn.acme.test/', str_repeat('x', 2049)] as $base) {
            $result = $this->finished();
            $result['items'][0]['custom_js_response']['base_url'] = $base;
            $result['items'][0]['custom_js_response']['lazy_images'] = ['relative.png', 'https://cdn.acme.test/absolute.png'];
            $result['items'][0]['custom_js_response']['srcsets'] = ['relative.png 1x, https://cdn.acme.test/absolute.png 2x'];
            $this->poll($result);
            $page = $provider->result($this->s->acme->id, self::TASK, self::URL);
            self::assertNotNull($page);
            self::assertArrayHasKey('reference_base_url', $page);
            self::assertArrayHasKey('lazy_images', $page);
            self::assertArrayHasKey('responsive_images', $page);
            self::assertNull($page['reference_base_url']);
            self::assertSame(['https://cdn.acme.test/absolute.png'], $page['lazy_images']);
            self::assertSame(['https://cdn.acme.test/absolute.png'], $page['responsive_images']);
        }
    }

    public function testFailedRetrievalResumesTheSavedTaskWithoutAnotherCharge(): void
    {
        $this->request();
        $this->runCheck();
        $this->runCheck();
        $oldRequestId = $this->storedResult()->params['_request_id'];
        $invalid = $this->finished();
        $invalid['items'][0]['custom_js_response']['url'] = 'https://foreign.test/';
        $this->poll($invalid);
        $this->runCheck();
        self::assertSame('failed', $this->storedResult()->status);
        $this->request();
        self::assertNotSame($oldRequestId, $this->storedResult()->params['_request_id']);
        self::assertIsString($oldRequestId);
        $this->s->container->make(RenderedAuditService::class)->run($this->s->acmeProject->id, $this->s->acme->id, $oldRequestId, true);
        self::assertTrue($this->storedResult()->isQueued());
        $this->poll($this->finished());
        $this->runCheck();
        self::assertSame('done', $this->storedResult()->status);
        self::assertCount(1, $this->data->usage);
        self::assertCount(1, $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
    }

    public function testTemporaryFreeRetrievalErrorRetriesWithoutResubmitting(): void
    {
        $this->request();
        $this->runCheck();
        $this->runCheck();
        $this->http->json('POST', $this->host . '/v3/on_page/pages', ['status_code' => 20000,
            'tasks' => [['id' => self::TASK, 'status_code' => 50000, 'status_message' => 'Temporary failure']]]);
        $id = $this->storedResult()->params['_request_id'];
        self::assertIsString($id);
        try {
            $this->s->container->make(RenderedAuditService::class)->run($this->s->acmeProject->id, $this->s->acme->id, $id, false);
            self::fail('A free retrieval error should remain retryable.');
        } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertFalse($e->chargeUncertain);
        }
        self::assertTrue($this->storedResult()->isQueued());
        $this->poll($this->finished());
        $this->runCheck();
        self::assertSame('done', $this->storedResult()->status);
        self::assertCount(1, $this->data->usage);
        self::assertCount(1, $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
    }

    public function testKnownFailedTaskCanBeExplicitlyReplacedWithANewPaidCheck(): void
    {
        $this->request();
        $this->runCheck();
        $this->runCheck();
        $this->poll(['crawl_progress' => 'finished', 'items' => []]);
        $this->runCheck();
        self::assertSame('failed', $this->storedResult()->status);
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringContainsString('Start new paid check', (string) $owner->get($this->base())->getBody());
        self::assertSame(303, $owner->post($this->base() . '/render', ['url' => self::URL, 'fresh' => '1'])->getStatusCode());
        $this->runCheck();
        self::assertCount(2, $this->data->usage);
        self::assertCount(2, $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
    }

    public function testBudgetRobotsPermissionsAndDeletedProjectPreventSubmission(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base());
        self::assertSame(404, $owner->post($this->base() . '/render', ['url' => 'https://other.test/'])->getStatusCode());
        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewer->get($this->base());
        self::assertSame(403, $viewer->post($this->base() . '/render', ['url' => self::URL])->getStatusCode());
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/');
        self::assertSame(404, $rival->post($this->base() . '/render', ['url' => self::URL])->getStatusCode());
        $this->data->saveBudget($this->s->acme->id, DataForSeoClient::PROVIDER, new ApiBudget(0.001, 0.001, 0.001), $this->s->owner->id);
        $this->request();
        $this->runCheck();
        $this->runCheck();
        self::assertSame('failed', $this->storedResult()->status);
        self::assertSame([], $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
        $this->data->saveBudget($this->s->acme->id, DataForSeoClient::PROVIDER, ApiBudget::unlimited(), $this->s->owner->id);
        $this->request();
        $this->s->dir->deleted[$this->s->acmeProject->id] = true;
        $this->runCheck();
        self::assertSame('failed', $this->storedResult()->status);
        self::assertSame([], $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH));
    }

    public function testRobotsExclusionAndPrivateDnsMakeNoPaidRequest(): void
    {
        $this->http->on('GET', 'https://shop.acme.test/robots.txt', static fn(): HttpResponse => new HttpResponse(200, "User-agent: SEO-Control-Center\nDisallow: /js"));
        $this->request();
        $this->runCheck();
        self::assertSame('failed', $this->storedResult()->status);
        self::assertSame([], $this->data->usage);
        $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\FakeHostResolver::class)->map['shop.acme.test'] = ['127.0.0.1'];
        $this->request();
        $before = count($this->http->requests);
        $this->runCheck();
        self::assertSame('failed', $this->storedResult()->status);
        self::assertSame($before, count($this->http->requests));
        self::assertSame([], $this->data->usage);
    }

    public function testSitewideCrawlKeepsAllPagesAndPagedResourceStatusesWithoutAnotherCharge(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base());
        self::assertSame(303, $owner->post($this->base() . '/render', ['url' => self::URL, 'max_pages' => '10', 'dismiss_cookie_popup' => '1'])->getStatusCode());
        $this->runCheck();
        $this->runCheck();
        $page = $this->finished()['items'][0];
        $second = $page;
        $second['url'] = 'https://shop.acme.test/second';
        $second['custom_js_response']['url'] = $second['url'];
        $this->poll(['crawl_progress' => 'finished', 'total_items_count' => 2, 'items' => [$page, $second]]);
        $this->http->on('POST', $this->host . '/v3/on_page/resources', static function (string $url, array $headers, ?string $requestBody): HttpResponse {
            $body = json_decode((string) $requestBody, true, flags: JSON_THROW_ON_ERROR)[0];
            $offset = $body['offset'];
            $rows = array_map(static fn(int $i): array => ['url' => 'https://shop.acme.test/r' . $i . '.css', 'status_code' => 404, 'resource_type' => 'stylesheet'], $offset === 0 ? range(1, 100) : [101]);
            return new HttpResponse(200, json_encode(['status_code' => 20000, 'tasks' => [['id' => self::TASK, 'status_code' => 20000, 'result' => [['crawl_progress' => 'finished', 'total_items_count' => 101, 'items' => $rows]]]]], JSON_THROW_ON_ERROR));
        });
        $this->http->on('POST', $this->host . '/v3/on_page/waterfall', static function (string $url, array $headers, ?string $requestBody): HttpResponse {
            $body = json_decode((string) $requestBody, true, flags: JSON_THROW_ON_ERROR)[0];
            return new HttpResponse(200, json_encode(['status_code' => 20000, 'tasks' => [['id' => self::TASK, 'status_code' => 20000, 'result' => [['crawl_progress' => 'finished', 'items' => [['page_url' => $body['url'], 'resources' => []]]]]]]], JSON_THROW_ON_ERROR));
        });
        for ($step = 0; $step < 5 && $this->storedResult()->isQueued(); ++$step) {
            $this->runCheck();
        }
        self::assertSame('done', $this->storedResult()->status);
        self::assertCount(2, $this->storedResult()->items);
        self::assertCount(101, $this->storedResult()->items[1]['browser_resources']);
        self::assertCount(1, $this->data->usage);
        self::assertCount(1, $this->s->container->make(RenderedAuditService::class)->history($this->s->acmeProject));
        $body = json_decode((string) $this->http->requestsTo($this->host . RenderedPageProvider::SUBMIT_PATH)[0]['body'], true, flags: JSON_THROW_ON_ERROR)[0];
        self::assertSame(10, $body['max_crawl_pages']);
        self::assertTrue($body['disable_cookie_popup']);
        self::assertNotEmpty(\SeoControlCenter\Modules\SiteAudit\RenderedFindings::issues($this->storedResult()));
        $snapshotId = $this->storedResult()->params['_request_id'];
        self::assertIsString($snapshotId);
        $historyPage = $owner->get($this->base() . '?rendered_snapshot=' . $snapshotId);
        self::assertSame(200, $historyPage->getStatusCode());
        self::assertStringContainsString('r101.css', (string) $historyPage->getBody());
        self::assertSame(404, $owner->get($this->base() . '?rendered_snapshot=foreign')->getStatusCode());
    }

    public function testLighthouseIsSavedAndFreePolledWithDeviceAndUsefulMetricsValidation(): void
    {
        $path = '/v3/on_page/lighthouse/task_post';
        $this->http->json('POST', $this->host . $path, ['status_code' => 20000, 'cost' => 0.005, 'tasks' => [['id' => self::TASK, 'status_code' => 20100, 'cost' => 0.005]]]);
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base());
        self::assertSame(303, $owner->post($this->base() . '/render', ['url' => self::URL, 'mode' => 'lighthouse'])->getStatusCode());
        $this->runCheck();
        $this->runCheck();
        $result = ['requestedUrl' => self::URL, 'finalUrl' => self::URL, 'configSettings' => ['formFactor' => 'mobile'], 'lighthouseVersion' => '12.0', 'categories' => ['performance' => ['score' => 0.75]], 'audits' => ['largest-contentful-paint' => ['numericValue' => 2700], 'cumulative-layout-shift' => ['numericValue' => 0.03]]];
        $get = $this->host . '/v3/on_page/lighthouse/task_get/json/' . self::TASK;
        $this->http->json('GET', $get, ['status_code' => 20000, 'tasks' => [['id' => self::TASK, 'status_code' => 20000, 'result' => [$result]]]]);
        $this->runCheck();
        self::assertSame('done', $this->storedResult()->status);
        self::assertSame(2700.0, $this->storedResult()->items[0]['metrics']['largest-contentful-paint']);
        self::assertCount(1, $this->data->usage);
        $provider = $this->s->container->make(RenderedPageProvider::class);
        foreach ([[...$result, 'finalUrl' => 'https://foreign.test/'], [...$result, 'configSettings' => ['formFactor' => 'desktop']], [...$result, 'audits' => []]] as $invalid) {
            $this->http->json('GET', $get, ['status_code' => 20000, 'tasks' => [['id' => self::TASK, 'status_code' => 20000, 'result' => [$invalid]]]]);
            try {
                $provider->lighthouse($this->s->acme->id, self::TASK, self::URL);
                self::fail('Mismatched or empty lab data must be rejected.');
            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                self::assertFalse($e->chargeUncertain);
            }
        }
        self::assertCount(1, $this->http->requestsTo($this->host . $path));
    }

    private function request(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base());
        self::assertSame(303, $owner->post($this->base() . '/render', ['url' => self::URL])->getStatusCode());
    }

    private function runCheck(): void
    {
        $id = $this->storedResult()->params['_request_id'];
        self::assertIsString($id);
        $this->s->container->make(RenderedAuditService::class)->run($this->s->acmeProject->id, $this->s->acme->id, $id, true);
        // Existing lifecycle tests finish the new free evidence phases, never another submission.
        for ($step = 0; $step < 3 && isset($this->storedResult()->params['_render_rows']) && $this->storedResult()->isQueued(); ++$step) {
            $this->s->container->make(RenderedAuditService::class)->run($this->s->acmeProject->id, $this->s->acme->id, $id, true);
        }
    }

    private function storedResult(): ResearchResult
    {
        $result = $this->s->container->make(RenderedAuditService::class)->current($this->s->acmeProject);
        self::assertNotNull($result);
        return $result;
    }

    private function base(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/site-audit';
    }

    /** @param array<string, mixed> $result */
    private function poll(array $result): void
    {
        $this->http->json('POST', $this->host . '/v3/on_page/pages', ['status_code' => 20000, 'cost' => 0,
            'tasks' => [['id' => self::TASK, 'status_code' => 20000, 'cost' => 0, 'result' => [$result]]]]);
    }

    /** @return array<string, mixed> */
    private function finished(): array
    {
        return ['crawl_progress' => 'finished', 'items' => [['url' => self::URL, 'status_code' => 200,
            'meta' => ['cumulative_layout_shift' => 0.12], 'page_timing' => ['largest_contentful_paint' => 2800],
            'custom_js_response' => ['url' => self::URL, 'title' => '<script>unsafe', 'description' => 'Browser description',
                'h1s' => ['Dynamic heading'], 'links' => ['/dynamic', 'javascript:alert(1)', '/dynamic'], 'missing_alt' => 2,
                'resources' => array_map(static fn(int $i): array => ['url' => '/background-' . $i . '.png', 'kind' => 'css_background'], range(1, 120))]]]];
    }
}

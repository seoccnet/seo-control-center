<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Alerts\AlertDetector;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\Indexing\IndexingService;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIndexing;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Scenario;

final class IndexingHttpTest extends TestCase
{
    private function connect(Scenario $s): int
    {
        $google = $s->container->make(InMemoryIntegrations::class);
        $connection = $google->upsert($s->acme->id, 'google', 'demo@example.test', 'https://www.googleapis.com/auth/webmasters.readonly', $s->owner->id);
        $google->link($s->acmeProject->id, $connection->id, 'sc-domain:shop.acme.test', $s->owner->id);
        $s->container->make(\SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens::class)->store($connection->id, new \SeoControlCenter\Modules\SearchConsole\Google\GoogleTokenSet('demo-access', 'demo-refresh', 3600, 'https://www.googleapis.com/auth/webmasters.readonly'));
        return $connection->id;
    }

    public function testManualEvidenceFailuresWatchChangesAndNoReadSideCalls(): void
    {
        $s = new Scenario();
        $this->connect($s);
        $url = 'https://shop.acme.test/Guide?Color=Blue';
        $base = '/projects/' . $s->acmeProject->publicId . '/indexing';
        $browser = $s->loggedIn($s->owner);
        $html = (string) $browser->get($base)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('No saved checks', $html);
        self::assertSame(303, $browser->post($base, ['url' => $url, 'action' => 'inspect'])->getStatusCode());
        $data = $s->container->make(InMemoryIndexing::class);
        self::assertCount(1, $data->jobs);
        $service = $s->container->make(IndexingService::class);
        $binding = (string) $service->binding($s->acmeProject->id);
        self::assertSame('unchecked', $service->saved($s->acmeProject, $url)?->status($service->staleBefore()));
        $http = $s->container->make(FakeHttpClient::class);
        self::assertCount(0, $http->requests);
        $http->json('POST', 'https://searchconsole.googleapis.com/', ['inspectionResult' => ['indexStatusResult' => ['verdict' => 'PASS', 'coverageState' => '<script>Indexed Ω</script>', 'googleCanonical' => $url, 'lastCrawlTime' => '2026-10-01T10:15:30Z']]]);
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $binding, $url, Uuid::v7());
        self::assertSame('indexed', $service->saved($s->acmeProject, $url)?->status($service->staleBefore()));
        self::assertSame('2026-10-01 10:15:30', $service->saved($s->acmeProject, $url)?->result?->lastCrawl);
        self::assertSame([], $data->changes($s->acmeProject->id, $binding, '2000-01-01'));
        $request = $http->requests[0];
        self::assertSame('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', $request['url']);
        self::assertSame(['inspectionUrl' => $url, 'siteUrl' => 'sc-domain:shop.acme.test', 'languageCode' => 'en-US'], json_decode((string) $request['body'], true));
        $html = (string) $browser->get($base)->getBody();
        self::assertStringContainsString('&lt;script&gt;Indexed Ω&lt;/script&gt;', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertCount(1, $http->requests, 'Reading the UI does not inspect Google.');
        self::assertSame(303, $browser->post($base, ['url' => $url, 'action' => 'watch', 'watch' => '1'])->getStatusCode());
        self::assertTrue($service->saved($s->acmeProject, $url)?->watched);
        self::assertCount(1, $data->jobs, 'Watch changes do not call the provider.');
        $http->json('POST', 'https://searchconsole.googleapis.com/', ['error' => ['message' => 'SECRET_TOKEN private URL']], 429);
        try {
            $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $binding, $url, Uuid::v7());
            self::fail('Expected retryable failure.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertStringNotContainsString('SECRET_TOKEN', $e->getMessage());
        }
        self::assertSame('error', $service->saved($s->acmeProject, $url)?->status($service->staleBefore()));
        $retained = $service->saved($s->acmeProject, $url);
        self::assertNotNull($retained);
        self::assertNotNull($retained->result);
        self::assertSame('PASS', $retained->result->verdict);
        self::assertSame([], $data->changes($s->acmeProject->id, $binding, '2000-01-01'));
        $http->json('POST', 'https://searchconsole.googleapis.com/', ['inspectionResult' => ['indexStatusResult' => ['verdict' => 'FAIL', 'googleCanonical' => 'https://shop.acme.test/other']]]);
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $binding, $url, Uuid::v7());
        self::assertSame('problem', $service->saved($s->acmeProject, $url)?->status($service->staleBefore()));
        self::assertCount(1, $data->changes($s->acmeProject->id, $binding, '2000-01-01'));
        $events = $s->container->make(AlertDetector::class)->project($s->acmeProject->id, $s->acmeProject->publicId, $s->acmeProject->name);
        self::assertCount(1, $events);
        self::assertSame(AlertType::IndexingChange, $events[0]['type']);
        self::assertStringContainsString(rawurlencode($url), $events[0]['url']);
        $browser->get($base);
        self::assertSame(303, $browser->post($base, ['url' => $url, 'action' => 'watch', 'watch' => '0'])->getStatusCode());
        self::assertFalse($service->saved($s->acmeProject, $url)?->watched);
        self::assertSame('FAIL', $service->saved($s->acmeProject, $url)?->result?->verdict);
    }

    public function testCanonicalMismatchAndRichResultErrorsAreFlaggedAndFilterable(): void
    {
        $s = new Scenario();
        $this->connect($s);
        $base = '/projects/' . $s->acmeProject->publicId . '/indexing';
        $browser = $s->loggedIn($s->owner);
        $service = $s->container->make(IndexingService::class);
        $binding = (string) $service->binding($s->acmeProject->id);
        $http = $s->container->make(FakeHttpClient::class);
        $mismatch = 'https://shop.acme.test/dupe';
        $clean = 'https://shop.acme.test/fine';
        foreach ([$mismatch, $clean] as $url) {
            $browser->get($base);
            $browser->post($base, ['url' => $url, 'action' => 'inspect']);
        }
        $http->json('POST', 'https://searchconsole.googleapis.com/', ['inspectionResult' => [
            'indexStatusResult' => ['verdict' => 'NEUTRAL', 'googleCanonical' => 'https://shop.acme.test/original', 'userCanonical' => $mismatch, 'sitemap' => ['https://shop.acme.test/sitemap.xml']],
            'richResultsResult' => ['verdict' => 'FAIL', 'detectedItems' => [['richResultType' => 'Product snippets', 'items' => [['issues' => [['issueMessage' => 'Missing field "<offers>"', 'severity' => 'ERROR']]]]]]],
        ]]);
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $binding, $mismatch, Uuid::v7());
        $http->json('POST', 'https://searchconsole.googleapis.com/', ['inspectionResult' => ['indexStatusResult' => ['verdict' => 'PASS', 'googleCanonical' => $clean, 'userCanonical' => $clean]]]);
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $binding, $clean, Uuid::v7());

        $html = (string) $browser->get($base)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('Google chose a different canonical than the page declares.', $html);
        self::assertStringContainsString('Error: Missing field &quot;&lt;offers&gt;&quot;', $html);
        self::assertStringContainsString('https://shop.acme.test/sitemap.xml', $html);
        foreach (['canonical', 'rich'] as $filter) {
            $filtered = (string) $browser->get($base . '?status=' . $filter)->getBody();
            self::assertStringContainsString('1 URLs', $filtered);
            self::assertStringContainsString($mismatch, $filtered);
            self::assertStringNotContainsString('shop.acme.test/fine', $filtered);
        }
    }

    public function testTenancyAuthorizationPropertyBoundsAndStaleJobs(): void
    {
        $s = new Scenario();
        $id = $this->connect($s);
        $base = '/projects/' . $s->acmeProject->publicId . '/indexing';
        foreach ([$s->rivalOwner, $s->unassigned] as $user) {
            $browser = $s->loggedIn($user);
            self::assertSame(404, $browser->get($base)->getStatusCode());
            $browser->get('/account');
            self::assertSame(404, $browser->post($base, ['url' => 'https://shop.acme.test/', 'action' => 'inspect'])->getStatusCode());
        }
        $viewer = $s->loggedIn($s->viewer);
        self::assertSame(200, $viewer->get($base)->getStatusCode());
        $viewer->get('/account');
        self::assertSame(403, $viewer->post($base, ['url' => 'https://shop.acme.test/', 'action' => 'inspect'])->getStatusCode());
        $owner = $s->loggedIn($s->owner);
        $owner->get($base);
        $csrf = $owner->csrfToken();
        foreach (['https://rival.test/', 'https://shop.acme.test:443/', 'https://user@shop.acme.test/', 'https://shop.acme.test/#fragment', 'https://shop.acme.test.evil.test/', 'file:///tmp/a', 'https://shop.acme.test/' . str_repeat('x', 2050)] as $bad) {
            self::assertSame(422, $owner->post($base, ['_csrf' => $csrf, 'url' => $bad])->getStatusCode(), $bad);
        }
        $url = 'https://shop.acme.test/page';
        self::assertSame(419, $owner->post($base, ['url' => $url], withCsrf: false)->getStatusCode());
        self::assertSame(303, $owner->post($base, ['_csrf' => $csrf, 'url' => $url])->getStatusCode());
        $service = $s->container->make(IndexingService::class);
        $binding = (string) $service->binding($s->acmeProject->id);
        $google = $s->container->make(InMemoryIntegrations::class);
        $google->link($s->acmeProject->id, $id, 'https://shop.acme.test/section/', $s->owner->id);
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $binding, $url, Uuid::v7());
        self::assertNull($service->saved($s->acmeProject, $url));
        self::assertCount(0, $s->container->make(FakeHttpClient::class)->requests);
        self::assertFalse(IndexingService::validUrl($s->acmeProject, 'https://shop.acme.test/section/', $url));
        self::assertTrue(IndexingService::validUrl($s->acmeProject, 'https://shop.acme.test/section/', 'https://shop.acme.test/section/Page'));
        self::assertFalse(IndexingService::validUrl($s->acmeProject, 'sc-domain:acme.test.evil.test', $url));
    }

    public function testWeeklySchedulingPaginationQuotaAndMalformedProviderResponses(): void
    {
        $s = new Scenario();
        $s->container->instance(Clock::class, new FrozenClock(new \DateTimeImmutable('2026-10-05 12:00:00 UTC')));
        $this->connect($s);
        $service = $s->container->make(IndexingService::class);
        $data = $s->container->make(InMemoryIndexing::class);
        $binding = (string) $service->binding($s->acmeProject->id);
        for ($i = 0; $i < 51; ++$i) {
            $url = 'https://shop.acme.test/page-' . $i;
            self::assertSame($i < 50, $data->track($s->acmeProject->id, $binding, $url, true));
            if ($i < 50) {
                $data->dueRows[] = ['project_id' => $s->acmeProject->id, 'workspace_id' => $s->acme->id, 'public_id' => $s->acmeProject->publicId, 'binding' => $binding, 'url' => $url];
            }
        }
        self::assertCount(25, $service->listing($s->acmeProject, '', 'watched', 999)['items']);
        self::assertSame(2, $service->listing($s->acmeProject, '', '', 999)['page']);
        self::assertSame(11, $service->listing($s->acmeProject, 'page-1', '', 1)['total']);
        $service->schedule();
        self::assertCount(50, $data->jobs);
        $service->schedule();
        self::assertCount(50, $data->jobs, 'Due reservations prevent repeated scheduling.');
        $http = $s->container->make(FakeHttpClient::class);
        $http->on('POST', 'https://searchconsole.googleapis.com/', static fn(): HttpResponse => new HttpResponse(200, '{bad json'));
        try {
            $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $binding, 'https://shop.acme.test/page-0', Uuid::v7());
            self::fail('Expected incomplete response.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
        }
        self::assertNull($service->saved($s->acmeProject, 'https://shop.acme.test/page-0')?->result);
        $limiter = $s->container->make(RateLimiter::class);
        for ($i = 0; $i < 1000; ++$i) {
            $limiter->hit('inspection.daily:' . $s->container->make(Clock::class)->now()->format('Y-m-d'), 1000, 86400);
        }
        try {
            $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $binding, 'https://shop.acme.test/page-0', Uuid::v7());
            self::fail('Expected local budget limit.');
        } catch (IntegrationException $e) {
            self::assertStringContainsString('budget', $e->getMessage());
        }
        self::assertCount(1, $http->requests, 'Budget exhaustion prevents a provider call.');
        $clock = $s->container->make(Clock::class);
        self::assertInstanceOf(FrozenClock::class, $clock);
        $clock->advance('+8 days');
        $service->schedule();
        self::assertCount(100, $data->jobs);
    }
}

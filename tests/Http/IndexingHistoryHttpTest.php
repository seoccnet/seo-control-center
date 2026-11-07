<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\Indexing\IndexingService;
use SeoControlCenter\Modules\Indexing\Inspection;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIndexing;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIndexingEvidence;
use SeoControlCenter\Tests\Support\Scenario;

/** Indexing history (ADR-042, 0.37.0): separate states, a timeline and recorded requests. */
final class IndexingHistoryHttpTest extends TestCase
{
    private const string URL = 'https://shop.acme.test/guide';

    public function testARecordedRequestStaysUnindexedUntilAGoogleCheckShowsIt(): void
    {
        $s = new Scenario();
        $this->connect($s);
        $evidence = $s->container->make(InMemoryIndexingEvidence::class);
        $evidence->crawls[$s->acmeProject->id][self::URL] = ['status_code' => 200, 'noindex' => false, 'canonical' => null, 'redirect_to' => null, 'error' => null, 'crawled_at' => '2026-01-01 08:00:00'];
        $evidence->sitemaps[$s->acmeProject->id][self::URL] = ['first_seen_at' => '2026-01-01 09:00:00', 'submitted_at' => '2026-01-01 09:00:00'];
        $page = '/projects/' . $s->acmeProject->publicId . '/indexing/history?url=' . rawurlencode(self::URL);
        $browser = $s->loggedIn($s->owner);
        $html = (string) $browser->get($page)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertStringContainsString('Technically indexable', $html);
        self::assertStringContainsString('https://search.google.com/search-console/inspect?resource_id=sc-domain%3Ashop.acme.test&amp;id=https%3A%2F%2Fshop.acme.test%2Fguide', $html);
        self::assertStringContainsString('Submitted to IndexNow', $html);

        $today = $s->container->make(\SeoControlCenter\Core\Support\Clock::class)->now()->format('Y-m-d');
        $form = '/projects/' . $s->acmeProject->publicId . '/indexing/requests';
        self::assertSame(303, $browser->post($form, ['url' => self::URL, 'channel' => 'search_console', 'requested_on' => $today, 'evidence' => 'Search Console said: Indexing requested', 'note' => '<b>first</b>'])->getStatusCode());
        $indexing = $s->container->make(InMemoryIndexing::class);
        self::assertCount(1, $indexing->requests($s->acmeProject->id, self::URL));
        self::assertNotNull($indexing->find($s->acmeProject->id, (string) $s->container->make(IndexingService::class)->binding($s->acmeProject->id), self::URL), 'The URL is saved so a Google check can follow.');
        self::assertSame([], $s->container->make(FakeHttpClient::class)->requests, 'Recording sends nothing to Google.');
        $html = (string) $browser->get($page)->getBody();
        self::assertStringContainsString('&lt;b&gt;first&lt;/b&gt;', $html);
        $states = $this->states($s);
        self::assertSame('yes', $states['request']);
        self::assertSame('unknown', $states['indexed']);
        self::assertSame('unknown', $states['recrawl']);

        $service = $s->container->make(IndexingService::class);
        $binding = (string) $service->binding($s->acmeProject->id);
        $later = $s->container->make(\SeoControlCenter\Core\Support\Clock::class)->now()->modify('+2 days')->format('Y-m-d H:i:s');
        $indexing->save($s->acmeProject->id, $binding, self::URL, Inspection::fromArray(['verdict' => 'PASS', 'coverageState' => 'Submitted and indexed', 'googleCanonical' => self::URL, 'lastCrawlTime' => str_replace(' ', 'T', $later) . 'Z']), $later, Uuid::v7());
        $states = $this->states($s);
        self::assertSame('yes', $states['indexed']);
        self::assertSame('yes', $states['recrawl']);
    }

    public function testValidationViewersAndAddressesOutsideTheProject(): void
    {
        $s = new Scenario();
        $this->connect($s);
        $form = '/projects/' . $s->acmeProject->publicId . '/indexing/requests';
        $today = $s->container->make(\SeoControlCenter\Core\Support\Clock::class)->now()->format('Y-m-d');
        $tomorrow = $s->container->make(\SeoControlCenter\Core\Support\Clock::class)->now()->modify('+1 day')->format('Y-m-d');
        $owner = $s->loggedIn($s->owner);
        $owner->get('/account');
        $valid = ['url' => self::URL, 'channel' => 'search_console', 'requested_on' => $today, 'evidence' => 'Indexing requested', 'note' => ''];
        foreach ([['url' => 'https://elsewhere.test/guide'], ['channel' => 'api'], ['requested_on' => $tomorrow], ['requested_on' => '2026-02-30'], ['evidence' => 'ok']] as $change) {
            $owner->get('/account');
            self::assertSame(422, $owner->post($form, [...$valid, ...$change])->getStatusCode(), (string) json_encode($change));
        }
        self::assertSame([], $s->container->make(InMemoryIndexing::class)->requests);

        $viewer = $s->loggedIn($s->viewer);
        $html = (string) $viewer->get('/projects/' . $s->acmeProject->publicId . '/indexing/history?url=' . rawurlencode(self::URL))->getBody();
        self::assertStringNotContainsString('Record an indexing request', $html);
        $viewer->get('/account');
        self::assertSame(403, $viewer->post($form, $valid)->getStatusCode());

        $html = (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/indexing/history?url=' . rawurlencode('https://elsewhere.test/guide'))->getBody();
        self::assertStringContainsString('not on the project’s domain', $html);
        self::assertStringNotContainsString('Timeline', $html);
    }

    private function connect(Scenario $s): void
    {
        $google = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations::class);
        $connection = $google->upsert($s->acme->id, 'google', 'demo@example.test', 'https://www.googleapis.com/auth/webmasters.readonly', $s->owner->id);
        $google->link($s->acmeProject->id, $connection->id, 'sc-domain:shop.acme.test', $s->owner->id);
    }

    /** @return array<string, string> */
    private function states(Scenario $s): array
    {
        $history = $s->container->make(IndexingService::class)->history($s->acmeProject, self::URL)['history'];
        self::assertNotNull($history);

        return array_column($history->states, 'value', 'key');
    }
}

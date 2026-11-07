<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Analytics\LandingPageMetrics;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\BusinessMeasurementService;
use SeoControlCenter\Modules\Analytics\Ga4Service;
use SeoControlCenter\Tests\Support\Fakes\FakeGa4Provider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBusinessEvents;
use SeoControlCenter\Tests\Support\Fakes\InMemoryGa4;
use SeoControlCenter\Tests\Support\Ga4Fixture;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Conversion diagnostics (ADR-042): business events, their honest states,
 * the owner's test check and who may change them.
 */
final class BusinessMeasurementHttpTest extends TestCase
{
    private Scenario $s;

    private string $base;

    protected function setUp(): void
    {
        $this->s = new Scenario(clock: new FrozenClock(new \DateTimeImmutable('2026-10-23 12:00:00', new \DateTimeZone('UTC'))));
        $this->base = '/projects/' . $this->s->acmeProject->publicId . '/analytics';
    }

    public function testOrganicSessionsWithoutPurchasesAreUncertaintyNotZeroValue(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('Business measurement', $html);
        self::assertStringContainsString('Not configured', $html, 'No business events defined yet.');

        $this->connect();
        self::assertSame(303, $this->post($owner, $this->base . '/events', ['kind' => 'purchase', 'event_name' => 'purchase', 'label' => 'Seed order'])->getStatusCode());
        self::assertSame([28], $this->ga4()->queuedDays, 'Defining an event queues the next GA4 import.');
        self::assertStringContainsString('Unknown', (string) $owner->get($this->base)->getBody(), 'Before an import the state is unknown, never zero.');

        $this->provider()->result = new Ga4Report(array_map(static fn(string $day): LandingPageMetrics => new LandingPageMetrics($day, 'https://shop.acme.test/', 40, 0.0, 0.0), DateRange::endingOn('2026-10-22', 28)->eachDay()), 'EUR', 'Europe/Berlin', false, false);
        $this->provider()->eventsPerDay = ['purchase' => ['organic' => 0, 'total' => 0]];
        $this->import();

        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('Not observed', $html);
        self::assertStringContainsString('Organic Search sessions exist, but at least one business event was not recorded', $html);
        self::assertStringContainsString('not zero business value', $html);
        self::assertStringContainsString('1,120', $html, 'GA4 organic sessions are shown next to Search Console clicks.');

        $this->provider()->eventsPerDay = ['purchase' => ['organic' => 1, 'total' => 3]];
        $this->import();
        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('GA4 recorded 84 in 28 days, 28 of them in Organic Search sessions.', $html);
    }

    public function testPeriodEndsOnTheLastGa4DayAndPartialClicksSayWhatTheyCover(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $this->connect();
        // GA4 data ends the day before yesterday, as on a live install early in the UTC morning.
        $range = DateRange::endingOn('2026-10-21', 28);
        $this->ga4()->saveReport($this->s->acmeProject->id, $range, new Ga4Report(array_map(static fn(string $day): LandingPageMetrics => new LandingPageMetrics($day, 'https://shop.acme.test/', 10, 0.0, 0.0), $range->eachDay()), 'EUR', 'America/Los_Angeles', false, false), '123');
        $search = $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class);
        $search->upsertDailyTotals($this->s->acmeProject->id, array_map(static fn(string $day): \SeoControlCenter\Domain\SearchConsole\DailyMetrics => new \SeoControlCenter\Domain\SearchConsole\DailyMetrics($day, new \SeoControlCenter\Domain\SearchConsole\Metrics(3, 0, 0.0)), array_slice($range->eachDay(), 0, 26)));

        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('2026-09-24 – 2026-10-21', $html, 'The period ends on the last imported GA4 day, not yesterday.');
        self::assertStringContainsString('280', $html, 'GA4 organic sessions over 28 complete days.');
        self::assertStringContainsString('<strong>Partial:</strong> 26 of 28 days', $html);
        self::assertStringContainsString('>78<', $html, 'Clicks over the 26 imported days are shown, labelled partial.');
    }

    public function testFailedEventImportIsItsOwnStateAndKeepsLandingPages(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $this->connect();
        $owner->get($this->base);
        $this->post($owner, $this->base . '/events', ['kind' => 'lead', 'event_name' => 'generate_lead']);
        $this->provider()->result = new Ga4Report([new LandingPageMetrics('2026-10-22', 'https://shop.acme.test/', 7, 0.0, 0.0)], 'EUR', 'UTC', false, false);
        $this->provider()->eventsError = new IntegrationException('Google Analytics refused the GA4 report request (HTTP 403).', false);
        $this->import();

        self::assertSame(7, $this->ga4()->totals($this->s->acmeProject->id, new DateRange('2026-10-22', '2026-10-22'))['sessions'], 'The landing-page import still succeeds.');
        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('Import failed', $html);
        self::assertStringContainsString('HTTP 403', $html);
    }

    public function testValidationLimitsAndRemoval(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base);
        self::assertSame(422, $this->post($owner, $this->base . '/events', ['kind' => 'purchase', 'event_name' => '1bad name'])->getStatusCode());
        self::assertSame(422, $this->post($owner, $this->base . '/events', ['kind' => 'refund', 'event_name' => 'refund'])->getStatusCode());
        self::assertSame(303, $this->post($owner, $this->base . '/events', ['kind' => 'purchase', 'event_name' => 'purchase'])->getStatusCode());
        self::assertSame(422, $this->post($owner, $this->base . '/events', ['kind' => 'other', 'event_name' => 'purchase'])->getStatusCode(), 'One definition per GA4 event.');
        for ($i = 1; $i < 10; $i++) {
            $this->post($owner, $this->base . '/events', ['kind' => 'other', 'event_name' => 'goal_' . $i]);
        }
        self::assertSame(422, $this->post($owner, $this->base . '/events', ['kind' => 'other', 'event_name' => 'goal_10'])->getStatusCode(), 'At most ten per project.');

        $events = $this->events()->events($this->s->acmeProject->id);
        self::assertSame(303, $this->post($owner, $this->base . '/events/' . $events[0]->publicId . '/delete')->getStatusCode());
        self::assertCount(9, $this->events()->events($this->s->acmeProject->id));
        self::assertSame(404, $this->post($owner, $this->base . '/events/' . $events[0]->publicId . '/delete')->getStatusCode());
        self::assertContains('analytics.business_event_added', $this->s->audit()->actions());
        self::assertContains('analytics.business_event_removed', $this->s->audit()->actions());
    }

    public function testViewersCannotChangeEventsOrStartChecks(): void
    {
        $this->connect();
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base);
        $this->post($owner, $this->base . '/events', ['kind' => 'purchase', 'event_name' => 'purchase']);
        $viewer = $this->s->loggedIn($this->s->viewer);
        $html = (string) $viewer->get($this->base)->getBody();
        self::assertStringContainsString('purchase', $html, 'Viewers see the states.');
        self::assertStringNotContainsString('Add event', $html);
        $viewer->get('/account');
        self::assertSame(403, $this->post($viewer, $this->base . '/events', ['kind' => 'lead', 'event_name' => 'generate_lead'])->getStatusCode());
        self::assertSame(403, $this->post($viewer, $this->base . '/events/check', ['note' => 'x'])->getStatusCode());
        self::assertCount(1, $this->events()->events($this->s->acmeProject->id));
    }

    public function testTestCheckReadsRealtimeOnlyAfterTheOwnerAsks(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base);
        self::assertSame(409, $this->post($owner, $this->base . '/events/check', ['note' => 'order'])->getStatusCode(), 'No event, no check.');
        $this->connect();
        $this->post($owner, $this->base . '/events', ['kind' => 'purchase', 'event_name' => 'purchase']);
        $this->post($owner, $this->base . '/events', ['kind' => 'lead', 'event_name' => 'generate_lead']);
        self::assertSame(303, $this->post($owner, $this->base . '/events/check', ['note' => 'Test order #1042'])->getStatusCode());
        self::assertCount(1, $this->ga4()->queuedChecks);
        self::assertStringContainsString('Waiting for cron', (string) $owner->get($this->base)->getBody());

        $this->provider()->realtimeCounts = ['purchase' => 1];
        $this->s->container->make(BusinessMeasurementService::class)->runCheck($this->ga4()->queuedChecks[0]);
        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('1 received', $html);
        self::assertStringContainsString('not received', $html, 'The lead event stays visibly not received.');
        self::assertStringContainsString('Test order #1042', $html);

        $this->post($owner, $this->base . '/events/check');
        $this->post($owner, $this->base . '/events/check');
        self::assertSame(429, $this->post($owner, $this->base . '/events/check')->getStatusCode(), 'Checks are rate-limited.');
    }

    /** @param array<string, string> $data */
    private function post(\SeoControlCenter\Tests\Support\Browser $browser, string $path, array $data = []): \Psr\Http\Message\ResponseInterface
    {
        $browser->get('/account');

        return $browser->post($path, $data);
    }

    private function connect(): void
    {
        $this->ga4()->saveCredentials($this->s->acme->id, Ga4Fixture::credentials());
        $this->ga4()->link($this->s->acmeProject->id, '123');
    }

    private function import(): void
    {
        $this->s->container->make(Ga4Service::class)->run($this->s->acmeProject->id, $this->s->acme->id, $this->s->acmeProject->publicId);
    }

    private function ga4(): InMemoryGa4
    {
        return $this->s->container->make(InMemoryGa4::class);
    }

    private function provider(): FakeGa4Provider
    {
        return $this->s->container->make(FakeGa4Provider::class);
    }

    private function events(): InMemoryBusinessEvents
    {
        return $this->s->container->make(InMemoryBusinessEvents::class);
    }
}

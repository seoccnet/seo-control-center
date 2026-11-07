<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\LabTests\LabResult;
use SeoControlCenter\Domain\LabTests\LabRun;
use SeoControlCenter\Domain\WebVitals\WebVitalRecord;
use SeoControlCenter\Modules\LabTests\LabTestService;
use SeoControlCenter\Tests\Support\Fakes\FakeLabProviders;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLabTests;
use SeoControlCenter\Tests\Support\Fakes\InMemoryWebVitals;
use SeoControlCenter\Tests\Support\Scenario;

/** Improve → Speed tests (ADR-040, F2). */
final class LabTestsHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testPageSpeedProfileRunsFreeAndShowsBesideFieldData(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url(), ['url' => 'https://shop.acme.test/seeds', 'device' => 'mobile', 'source' => 'pagespeed']);
        self::assertStringContainsString('Add a Google API key under Integrations', (string) $owner->get($this->url())->getBody(), 'PageSpeed needs the workspace key.');

        $this->vitals()->saveKey($this->s->acme->id, 'AIzaSyTestKey0123456789abcdef');
        $this->vitals()->save($this->s->acmeProject->id, new WebVitalRecord('https://shop.acme.test/seeds', 2100.0, 180.0, 0.04, '2026-09-01', '2026-09-28', '2026-10-01 10:00:00', 'available', 'PHONE'));
        $owner->post($this->url(), ['url' => 'https://shop.acme.test/seeds', 'device' => 'mobile', 'source' => 'pagespeed', 'weekly' => '1']);
        $profile = array_values($this->tests()->profiles)[0];
        self::assertSame(['mobile', 'pagespeed', true], [$profile->device, $profile->source, $profile->weekly]);
        self::assertCount(1, $this->tests()->queued, 'A PageSpeed profile runs once right away.');
        self::assertContains('lab_tests.profile_added', $this->s->audit()->actions());

        $service = $this->service();
        self::assertNull($service->execute($this->queued(0)));
        self::assertSame(['pagespeed:mobile:https://shop.acme.test/seeds'], $this->providers()->calls);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Score 90', $html);
        self::assertStringContainsString('1,800 ms', $html);
        self::assertStringContainsString('real-user data', $html, 'CrUX field data of the same page and device beside the lab runs.');
        self::assertStringContainsString('Never averaged with lab runs', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));

        // A second run shows the change since the previous one.
        $this->providers()->pageSpeed[] = new LabResult(0.84, 2300.0, 0.05, 210.0, 950.0, 1700.0, '12.0.0');
        $owner->post($this->url() . '/' . $profile->publicId . '/run');
        self::assertNull($service->execute($this->queued(1)));
        self::assertStringContainsString('Score 84 (-6 since the previous run)', (string) $owner->get($this->url())->getBody());
        $owner->post($this->url(), ['url' => 'https://shop.acme.test/seeds', 'device' => 'mobile', 'source' => 'pagespeed']);
        self::assertStringContainsString('already has a profile for this device and source', (string) $owner->get($this->url())->getBody());
        $owner->post($this->url(), ['url' => 'https://rival.test/', 'device' => 'mobile', 'source' => 'pagespeed']);
        self::assertStringContainsString('HTTPS page address on this project', (string) $owner->get($this->url())->getBody());
    }

    public function testPaidRunsNeedConfirmationAndAreCollectedLater(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url(), ['url' => 'https://shop.acme.test/', 'device' => 'desktop', 'source' => 'dataforseo']);
        $profile = array_values($this->tests()->profiles)[0];
        self::assertSame([], $this->tests()->queued, 'A paid profile never runs on its own.');
        $owner->post($this->url() . '/' . $profile->publicId . '/run');
        self::assertStringContainsString('Confirm the paid run', (string) $owner->get($this->url())->getBody());
        self::assertSame([], $this->tests()->queued);

        $owner->post($this->url() . '/' . $profile->publicId . '/run', ['confirm_paid' => '1']);
        $runId = $this->queued(0);
        $service = $this->service();
        self::assertSame(LabTestService::POLL_SECONDS, $service->execute($runId), 'Submitted, collected later.');
        self::assertSame(LabRun::RUNNING, $this->tests()->runs[$runId]->status);
        $this->providers()->collect = [null, new IntegrationException('DataForSEO is busy.', true), new LabResult(0.72, 3100.0, 0.12, 400.0, 1500.0, 3300.0, '12.1.0')];
        self::assertSame(LabTestService::POLL_SECONDS, $service->execute($runId), 'Still testing.');
        self::assertSame(LabTestService::POLL_SECONDS, $service->execute($runId), 'A retryable error waits.');
        self::assertNull($service->execute($runId));
        self::assertSame(['submit:desktop:https://shop.acme.test/', 'collect:01234567-89ab-cdef-0123-456789abcdef', 'collect:01234567-89ab-cdef-0123-456789abcdef', 'collect:01234567-89ab-cdef-0123-456789abcdef'], $this->providers()->calls);
        self::assertSame([LabRun::DONE, 0.005], [$this->tests()->runs[$runId]->status, $this->tests()->runs[$runId]->cost]);
        self::assertStringContainsString('Score 72', (string) $owner->get($this->url())->getBody());
        self::assertContains('lab_tests.run_requested', $this->s->audit()->actions());
    }

    public function testFailuresWeeklyScheduleDeleteAndPermissions(): void
    {
        $this->vitals()->saveKey($this->s->acme->id, 'AIzaSyTestKey0123456789abcdef');
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $this->providers()->pageSpeed[] = new IntegrationException('PageSpeed Insights refused the run (HTTP 403). Enable the PageSpeed Insights API in the Google Cloud project that owns the saved key.', false);
        $owner->post($this->url(), ['url' => 'https://shop.acme.test/a', 'device' => 'desktop', 'source' => 'pagespeed']);
        self::assertNull($this->service()->execute($this->queued(0)));
        self::assertStringContainsString('Failed: PageSpeed Insights refused the run (HTTP 403). Enable the PageSpeed Insights API', (string) $owner->get($this->url())->getBody());
        $profile = array_values($this->tests()->profiles)[0];

        $owner->post($this->url() . '/' . $profile->publicId . '/weekly', ['weekly' => '1']);
        self::assertTrue($this->tests()->profiles[$profile->id]->weekly);
        self::assertSame(1, $this->service()->schedule(), 'Due right away after switching weekly on.');
        self::assertSame(0, $this->service()->schedule(), 'Next week, not again now.');

        $viewer = $this->s->loggedIn($this->s->viewer);
        $page = (string) $viewer->get($this->url())->getBody();
        self::assertStringNotContainsString('Save profile', $page, 'Readers see results, not the forms.');
        self::assertSame(403, $viewer->post($this->url() . '/' . $profile->publicId . '/run')->getStatusCode());

        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/' . $profile->publicId . '/delete')->getStatusCode());
        self::assertSame([], $this->tests()->profiles);
        self::assertSame([], $this->tests()->runs);
        $owner->get($this->url());
        self::assertSame(404, $owner->post($this->url() . '/' . $profile->publicId . '/run')->getStatusCode());
    }

    /** The ID of the n-th queued run. */
    private function queued(int $index): int
    {
        $queued = $this->tests()->queued;
        self::assertArrayHasKey($index, $queued);

        return $queued[$index]['run_id'];
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/lab-tests';
    }

    private function tests(): InMemoryLabTests
    {
        $tests = $this->s->container->get(InMemoryLabTests::class);
        self::assertInstanceOf(InMemoryLabTests::class, $tests);

        return $tests;
    }

    private function providers(): FakeLabProviders
    {
        $providers = $this->s->container->get(FakeLabProviders::class);
        self::assertInstanceOf(FakeLabProviders::class, $providers);

        return $providers;
    }

    private function vitals(): InMemoryWebVitals
    {
        $vitals = $this->s->container->get(InMemoryWebVitals::class);
        self::assertInstanceOf(InMemoryWebVitals::class, $vitals);

        return $vitals;
    }

    private function service(): LabTestService
    {
        $service = $this->s->container->get(LabTestService::class);
        self::assertInstanceOf(LabTestService::class, $service);

        return $service;
    }
}

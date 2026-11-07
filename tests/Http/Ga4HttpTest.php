<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Analytics\LandingPageMetrics;
use SeoControlCenter\Modules\Analytics\Ga4Service;
use SeoControlCenter\Modules\Pages\PageSummary;
use SeoControlCenter\Tests\Support\Fakes\FakeGa4Provider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryGa4;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Ga4Fixture;
use SeoControlCenter\Tests\Support\Scenario;

final class Ga4HttpTest extends TestCase
{
    public function testDateComparisonsUseExactUrlsAndMissingCoverageStaysUnavailable(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/analytics';
        $data = $s->container->make(InMemoryGa4::class);
        $data->link($s->acmeProject->id, '123');
        $range = new \SeoControlCenter\Domain\Shared\DateRange('2026-09-08', '2026-10-05');
        $url = 'https://shop.acme.test/product';
        $prior = [];
        for ($i = 0; $i < 10000; ++$i) {
            $prior[] = new LandingPageMetrics('2026-09-07', 'https://shop.acme.test/other-' . $i, 1, 1, 2);
        }
        $prior[] = new LandingPageMetrics('2026-09-07', $url, 5, 0.5, 1);
        $data->saveReport($s->acmeProject->id, $range->previous(), new Ga4Report($prior, 'USD', 'UTC', false, false), '123');
        $data->saveReport($s->acmeProject->id, $range, new Ga4Report([new LandingPageMetrics('2026-10-05', $url, 15, 1.5, 10)], 'USD', 'UTC', false, false), '123');
        $html = (string) $owner->get($base . '?from=2026-09-08&to=2026-10-05')->getBody();
        self::assertStringContainsString('+10 sessions · +1.00 conversions · +9.00 USD', $html, 'The prior URL must not be lost behind a global previous-period row cap.');
        $data->saveReport($s->acmeProject->id, new \SeoControlCenter\Domain\Shared\DateRange('2026-06-01', '2026-06-28'), new Ga4Report([], 'USD', 'UTC', true, true), '123');
        self::assertStringContainsString('+10 sessions · +1.00 conversions · +9.00 USD', (string) $owner->get($base . '?from=2026-09-08&to=2026-10-05')->getBody(), 'Quality warnings outside the compared periods must not disable valid comparisons.');
        $data->saveReport($s->acmeProject->id, $range->previous(), new Ga4Report($prior, 'USD', 'UTC', false, true), '123');
        self::assertStringContainsString('Unavailable', (string) $owner->get($base . '?from=2026-09-08&to=2026-10-05')->getBody(), 'Thresholding in either compared period suppresses changes.');
        $missing = (string) $owner->get($base . '?from=2026-08-01&to=2026-10-05')->getBody();
        self::assertStringContainsString('Unavailable', $missing);
        self::assertSame(422, $owner->get($base . '?from=invalid&to=2026-10-05')->getStatusCode());
        self::assertSame(422, $owner->get($base . '?from=2024-01-01&to=2026-10-05')->getStatusCode());
        $data->saveCredentials($s->acme->id, Ga4Fixture::credentials());
        $owner->get($base);
        self::assertSame(303, $owner->post($base . '/import', ['days' => '90'])->getStatusCode());
        self::assertSame([90], $data->queuedDays);
        self::assertSame(422, $owner->post($base . '/import', ['days' => 'invalid'])->getStatusCode());
    }

    public function testCredentialsStayHiddenAndPropertyImportsShowPageRevenue(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $owner->get('/integrations/ga4');
        self::assertSame(303, $owner->post('/integrations/ga4', ['credentials' => Ga4Fixture::json()])->getStatusCode());
        self::assertStringNotContainsString('PRIVATE KEY', (string) $owner->get('/integrations/ga4')->getBody());
        $base = '/projects/' . $s->acmeProject->publicId;
        $owner->get($base . '/analytics');
        self::assertSame(422, $owner->post($base . '/analytics', ['property_id' => 'G-INVALID'])->getStatusCode());
        $owner->get($base . '/analytics');
        self::assertSame(303, $owner->post($base . '/analytics', ['property_id' => '123'])->getStatusCode());
        $data = $s->container->make(InMemoryGa4::class);
        self::assertCount(1, $data->queued);
        $provider = $s->container->make(FakeGa4Provider::class);
        $clock = $s->container->make(\SeoControlCenter\Core\Support\Clock::class);
        $url = 'https://shop.acme.test/pricing';
        $provider->result = new Ga4Report([new LandingPageMetrics($clock->now()->modify('-1 day')->format('Y-m-d'), $url, 20, 3.5, 249.0)], 'USD', 'UTC', false, true);
        $service = $s->container->make(Ga4Service::class);
        $service->run($s->acmeProject->id, $s->rival->id, $s->acmeProject->publicId);
        self::assertSame([], $provider->calls);
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId);
        self::assertCount(1, $provider->calls);
        $html = (string) $owner->get($base . '/analytics')->getBody();
        self::assertStringContainsString('249.00 USD', $html);
        self::assertStringContainsString('privacy thresholds', $html);
        $s->container->make(InMemoryPages::class)->pages[$s->acmeProject->id] = [new PageSummary($url, null, null)];
        self::assertStringContainsString('249.00 USD', (string) $owner->get($base . '/pages/details?url=' . rawurlencode($url))->getBody());
        $owner->get('/integrations/ga4');
        $owner->post('/integrations/ga4', ['credentials' => '']);
        self::assertFalse($data->hasCredentials($s->acme->id));
        self::assertNotNull($data->metadata($s->acmeProject->id));
    }
}

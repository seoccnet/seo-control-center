<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Forecasting\ForecastEngine;
use SeoControlCenter\Modules\Forecasting\ForecastRepository;
use SeoControlCenter\Modules\Forecasting\ForecastSeries;
use SeoControlCenter\Tests\Support\Fakes\InMemoryForecasts;
use SeoControlCenter\Tests\Support\OverviewFixture;

final class ForecastHttpTest extends TestCase
{
    public function testEmptyPageAndImmutableCapturePermissionCsrfAndTenantScope(): void
    {
        $s = OverviewFixture::scenario(false);
        $repo = new InMemoryForecasts();
        $s->container->instance(ForecastRepository::class, $repo);
        $browser = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/forecasts';
        self::assertSame(200, $browser->get($base)->getStatusCode());
        self::assertCount(0, $repo->saved, 'Reading never captures forecasts.');
        $values = [];
        for ($i = 0; $i < 180; $i++) {
            $values[ForecastEngine::date('2026-04-10', $i)] = 20.0;
        }
        $repo->sources[$s->acmeProject->id] = [new ForecastSeries('google:clicks', '<script>site</script>', 'g1', $values)];
        $html = (string) $browser->get($base)->getBody();
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertSame(419, $browser->post($base, ['horizon' => '14'], withCsrf: false)->getStatusCode());
        $browser->get($base);
        self::assertSame(303, $browser->post($base, ['horizon' => '14'])->getStatusCode());
        self::assertCount(1, $repo->saved);
        $first = json_encode($repo->saved, JSON_THROW_ON_ERROR);
        $browser->get($base);
        self::assertSame(303, $browser->post($base, ['horizon' => '14'])->getStatusCode());
        self::assertSame($first, json_encode($repo->saved, JSON_THROW_ON_ERROR), 'Repeated captures preserve the original prediction.');
        self::assertSame([], $repo->records($s->rivalProject));
        $viewer = $s->loggedIn($s->viewer);
        self::assertSame(200, $viewer->get($base)->getStatusCode());
        self::assertSame(403, $viewer->post($base, ['horizon' => '14'])->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->rivalOwner)->get($base)->getStatusCode());
        self::assertSame(422, $browser->get($base . '?horizon=99')->getStatusCode());
        $browser->get($base);
        self::assertSame(422, $browser->post($base . '/calendar', ['name' => 'Holiday', 'date' => '2026-02-30'])->getStatusCode());
        $browser->get($base);
        self::assertSame(422, $browser->post($base . '/calendar', ['name' => 'Holiday', 'date' => "2026-12-25\0"])->getStatusCode());
        $browser->get($base);
        self::assertSame(422, $browser->post($base . '/calendar', ['name' => "Bad\xFF", 'date' => '2026-12-25'])->getStatusCode());
        $browser->get($base);
        self::assertSame(303, $browser->post($base . '/calendar', ['name' => 'Holiday', 'date' => '2026-12-25'])->getStatusCode());
        self::assertCount(1, $repo->events($s->acmeProject));
        self::assertSame('2026-10-07', $repo->events($s->acmeProject)[0]->knownOn);
        self::assertSame([], $repo->events($s->rivalProject));
        $viewer->get($base);
        self::assertSame(403, $viewer->post($base . '/calendar', ['name' => 'Holiday', 'date' => '2026-12-25'])->getStatusCode());
        $browser->get($base);
        self::assertSame(303, $browser->post($base . '/tracking', ['enabled' => '0'])->getStatusCode());
        self::assertFalse($repo->tracking($s->acmeProject)['enabled']);
        $viewer->get($base);
        self::assertSame(403, $viewer->post($base . '/tracking', ['enabled' => '1'])->getStatusCode());
        $browser->get($base);
        self::assertSame(303, $browser->post($base . '/calendar/exclude', ['name' => 'Holiday', 'date' => '2026-12-25'])->getStatusCode());
        self::assertSame('2026-10-07', $repo->events($s->acmeProject)[0]->excludedOn);
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Forecasting\ForecastEngine;
use SeoControlCenter\Modules\Forecasting\ForecastRepository;
use SeoControlCenter\Modules\Forecasting\ForecastReviewJob;
use SeoControlCenter\Modules\Forecasting\ForecastSeries;
use SeoControlCenter\Modules\Forecasting\ForecastService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryForecasts;
use SeoControlCenter\Tests\Support\Scenario;

final class ForecastLifecycleTest extends TestCase
{
    public function testFrozenIssuedPredictionsScoreLaterWithoutRetrainingAndPropertyChangeWithholdsActuals(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-07 12:00:00 UTC'));
        $s = new Scenario(clock: $clock);
        $repo = new InMemoryForecasts();
        $s->container->instance(ForecastRepository::class, $repo);
        $values = [];
        for ($i = 0; $i < 180; $i++) {
            $values[ForecastEngine::date('2026-04-10', $i)] = 20.0;
        }
        $repo->sources[$s->acmeProject->id] = [new ForecastSeries('google:clicks', 'Site', 'property-one', $values)];
        $service = $s->container->make(ForecastService::class);
        $service->capture($s->owner, $s->acmeProject, 7, new ClientInfo(null, null, null));
        $before = json_encode($repo->saved, JSON_THROW_ON_ERROR);
        $report = $service->overview($s->acmeProject, 7);
        self::assertNull($report['history'][0]['actual']);
        self::assertSame(20.0, $report['history'][0]['prediction']);
        $values['2026-10-14'] = 50.0;
        $repo->sources[$s->acmeProject->id] = [new ForecastSeries('google:clicks', 'Site', 'property-one', $values)];
        $clock->advance('+8 days');
        $after = $service->overview($s->acmeProject, 7);
        self::assertSame(50.0, $after['history'][0]['actual']);
        self::assertSame(30.0, $after['history'][0]['error']);
        self::assertSame($before, json_encode($repo->saved, JSON_THROW_ON_ERROR), 'Scoring cannot replace the issued prediction.');
        $repo->sources[$s->acmeProject->id] = [new ForecastSeries('google:clicks', 'Site', 'property-two', $values)];
        self::assertNull($service->overview($s->acmeProject, 7)['history'][0]['actual']);
    }

    public function testDailyScoringSurvivesRetentionWeeklyIssuanceAndPause(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-07 12:00:00 UTC'));
        $s = new Scenario(clock: $clock);
        $repo = new InMemoryForecasts();
        $s->container->instance(ForecastRepository::class, $repo);
        $values = [];
        for ($i = 0; $i < 180; $i++) {
            $values[ForecastEngine::date('2026-04-10', $i)] = 20.0;
        }
        $repo->sources[$s->acmeProject->id] = [new ForecastSeries('google:clicks', 'Site', 'property-one', $values)];
        $service = $s->container->make(ForecastService::class);
        $client = new ClientInfo(null, null, null);
        $service->capture($s->owner, $s->acmeProject, 7, $client);
        for ($i = 0; $i < 8; $i++) {
            $values[ForecastEngine::date('2026-10-07', $i)] = 50.0;
        }
        $clock->advance('+8 days');
        $repo->sources[$s->acmeProject->id] = [new ForecastSeries('google:clicks', 'Site', 'property-one', $values)];
        $context = new JobContext('job', 'forecast.review', 1, 3, static fn(): bool => true, PHP_INT_MAX);
        $job = new ForecastReviewJob($s->container->make(ProjectRepository::class), $service);
        $payload = ['project' => $s->acmeProject->id, 'workspace' => $s->acmeProject->workspaceId, 'publicId' => $s->acmeProject->publicId];
        $job->handle([...$payload, 'workspace' => $s->rival->id], $context);
        self::assertCount(1, $repo->saved, 'Wrong workspace cannot run tracking.');
        $job->handle($payload, $context);
        self::assertCount(4, $repo->saved, 'Weekly issue adds all three horizons.');
        self::assertSame('2026-10-15', $repo->tracking($s->acmeProject)['checked']);
        $report = $service->overview($s->acmeProject, 7);
        self::assertSame(20.0, $report['history'][0]['prediction']);
        self::assertSame(50.0, $report['history'][0]['actual']);
        $repo->sources[$s->acmeProject->id] = [];
        self::assertSame(50.0, $service->overview($s->acmeProject, 7)['history'][0]['actual'], 'Saved actual survives source retention.');
        $service->setTracking($s->owner, $s->acmeProject, false, $client);
        $clock->advance('+8 days');
        $before = json_encode($repo->saved, JSON_THROW_ON_ERROR);
        $job->handle($payload, $context);
        self::assertSame($before, json_encode($repo->saved, JSON_THROW_ON_ERROR));
    }
}

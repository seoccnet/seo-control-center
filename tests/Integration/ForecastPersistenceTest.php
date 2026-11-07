<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Analytics\LandingPageMetrics;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Forecasting\ForecastEvent;
use SeoControlCenter\Modules\Forecasting\ForecastRepository;
use SeoControlCenter\Modules\Forecasting\ForecastSeries;

final class ForecastPersistenceTest extends FullStackTestCase
{
    public function testImmutableProjectWorkspaceRecordsAndSourceBindings(): void
    {
        $owner = $this->createUser('forecast@acme.test', 'Forecast');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace'];
        $id = '0192f0a0-0000-7000-8000-000000000093';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace->id, $id);
        self::assertNotNull($project);
        $this->app->instance(Clock::class, new FrozenClock(new \DateTimeImmutable('2026-10-09 12:00:00 UTC')));
        $repo = $this->app->make(ForecastRepository::class);
        $series = new ForecastSeries('google:clicks', 'Google clicks', 'google:shop.test::', ['2026-10-01' => 10.0]);
        $payload = ['key' => $series->key, 'label' => $series->label, 'method' => 'Recent average', 'target' => '2026-10-08', 'prediction' => 10.0, 'lower' => null, 'upper' => null];
        $repo->save($project, '2026-10-01', 7, $series, $payload);
        $repo->save($project, '2026-10-01', 7, $series, [...$payload, 'prediction' => 999.0]);
        $records = $repo->records($project);
        self::assertCount(1, $records);
        self::assertSame(10, $records[0]['payload']['prediction']);
        $repo->observe($project, (int) $records[0]['id'], 12.0);
        self::assertSame(12.0, $repo->records($project)[0]['actual']);
        self::assertSame(10, $repo->records($project)[0]['payload']['prediction']);
        $foreign = new Project($project->id, $project->publicId, $workspace->id + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertSame([], $repo->records($foreign));
        self::assertSame([], $repo->series($foreign, '2026-10-08'));
        $repo->save($foreign, '2026-10-01', 7, $series, $payload);
        self::assertCount(1, $repo->records($project));
        $this->app->make(SearchPerformanceStore::class)->upsertDailyTotals($project->id, [new DailyMetrics('2026-10-08', new Metrics(12, 100, 5))]);
        self::assertSame(['2026-10-08' => 12.0], $repo->observations($project, $series->key, hash('sha256', $series->binding), '2026-10-09'));
        self::assertSame([], $repo->observations($foreign, $series->key, hash('sha256', $series->binding), '2026-10-09'));
        self::assertSame([], $repo->observations($project, $series->key, hash('sha256', 'wrong-property'), '2026-10-09'));
        $repo->saveEvent($project, new ForecastEvent('Holiday', '2026-12-25', '2026-10-07'));
        $repo->saveEvent($project, new ForecastEvent('Holiday', '2026-12-25', '2026-10-08'));
        self::assertCount(1, $repo->events($project));
        self::assertSame('2026-10-07', $repo->events($project)[0]->knownOn);
        self::assertSame([], $repo->events($foreign));
        $repo->setTracking($project, true);
        self::assertCount(1, $repo->dueProjects('2026-10-09'));
        $repo->checked($project, '2026-10-09', true);
        self::assertSame([], $repo->dueProjects('2026-10-09'));
        self::assertCount(1, $repo->dueProjects('2026-10-10'));
        $repo->setTracking($project, false);
        self::assertSame([], $repo->dueProjects('2026-10-10'));
        $repo->excludeEvent($foreign, 'Holiday', '2026-12-25', '2026-10-08');
        self::assertNull($repo->events($project)[0]->excludedOn);
        $repo->excludeEvent($project, 'Holiday', '2026-12-25', '2026-10-08');
        self::assertSame('2026-10-08', $repo->events($project)[0]->excludedOn);
        $this->app->make(SearchPerformanceStore::class)->replaceDay($project->id, '2026-10-08', ['query' => [new DimensionRow('observed query', new Metrics(4, 100, 5))], 'page' => [new DimensionRow('https://shop.test/Page', new Metrics(5, 100, 5))]]);
        $ga4 = $this->app->make(Ga4Repository::class);
        $ga4->link($project->id, '123456');
        $ga4->saveReport($project->id, new DateRange('2026-10-07', '2026-10-08'), new Ga4Report([new LandingPageMetrics('2026-10-08', 'https://shop.test/', 8, 2.5, 0)], 'USD', 'America/Los_Angeles', false, false), '123456');
        $all = $repo->series($project, '2026-10-09');
        self::assertCount(5, $all);
        self::assertSame(['2026-10-08' => 4.0], $all[1]->values, 'Absent query days are not zero-filled.');
        self::assertSame(['2026-10-08' => 5.0], $all[2]->values);
        self::assertSame(['2026-10-07' => 0.0, '2026-10-08' => 8.0], $all[3]->values, 'GA4 successful empty import dates are measured zero.');
        self::assertSame(['2026-10-07' => 0.0, '2026-10-08' => 2.5], $all[4]->values);
        self::assertSame($all[4]->values, $repo->observations($project, $all[4]->key, hash('sha256', $all[4]->binding), '2026-10-09'));
        $ga4->saveReport($project->id, new DateRange('2026-10-08', '2026-10-08'), new Ga4Report([], 'USD', 'America/Los_Angeles', false, true), '123456');
        self::assertNotNull($repo->series($project, '2026-10-09')[3]->blocked);
        self::assertSame([], $repo->observations($project, $all[4]->key, hash('sha256', $all[4]->binding), '2026-10-09'));
        $this->db->execute('UPDATE projects SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$project->id]);
        self::assertSame([], $repo->records($project));
        self::assertSame([], $repo->series($project, '2026-10-09'));
        $repo->save($project, '2026-10-02', 7, $series, $payload);
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM forecast_snapshots'));
    }

    public function testOtherGoogleSurfacesBecomeSeriesOnlyWithRecentClicks(): void
    {
        $owner = $this->createUser('surfaces@acme.test', 'Surfaces');
        self::assertNotNull($owner['workspace']);
        $id = '0192f0a0-0000-7000-8000-000000000094';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $owner['workspace']->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($owner['workspace']->id, $id);
        self::assertNotNull($project);
        $this->app->instance(Clock::class, new FrozenClock(new \DateTimeImmutable('2026-10-09 12:00:00 UTC')));
        $extras = $this->app->make(SearchConsoleExtras::class);
        $extras->upsertSearchTypeDays($project->id, SearchType::Discover, [new DailyMetrics('2026-10-01', new Metrics(7, 400, 0)), new DailyMetrics('2026-10-03', new Metrics(0, 300, 0))]);
        $extras->upsertSearchTypeDays($project->id, SearchType::Image, [new DailyMetrics('2026-06-01', new Metrics(5, 900, 9))]);
        $repo = $this->app->make(ForecastRepository::class);

        $series = array_values(array_filter($repo->series($project, '2026-10-08'), static fn(ForecastSeries $s): bool => str_starts_with($s->key, 'google:surface:')));
        self::assertSame(['google:surface:discover'], array_map(static fn(ForecastSeries $s): string => $s->key, $series), 'Image search had no clicks in the last 28 days.');
        self::assertSame('Google Discover clicks · site', $series[0]->label);
        self::assertSame(['2026-10-01' => 7.0, '2026-10-03' => 0.0], $series[0]->values, 'Days without a row stay missing.');
        $google = array_values(array_filter($repo->series($project, '2026-10-08'), static fn(ForecastSeries $s): bool => $s->key === 'google:clicks'))[0];
        self::assertSame($google->binding, $series[0]->binding, 'Bound to the same Search Console property.');
        self::assertSame(['2026-10-01' => 7.0, '2026-10-03' => 0.0], $repo->observations($project, 'google:surface:discover', hash('sha256', $series[0]->binding), '2026-10-09'));
        self::assertSame([], $repo->observations($project, 'google:surface:bogus', hash('sha256', $series[0]->binding), '2026-10-09'));
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Alerts\AlertDetector;
use SeoControlCenter\Modules\Alerts\AlertService;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\PageMonitoring\PageMonitoringRepository;
use SeoControlCenter\Modules\PageMonitoring\PageMonitoringService;
use SeoControlCenter\Modules\PageMonitoring\PageSnapshot;
use SeoControlCenter\Tests\Http\AccessibilityTest;
use SeoControlCenter\Tests\Support\Fakes\FakeHostResolver;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

final class PageMonitoringTest extends FullStackTestCase
{
    public function testWatchQueueEvidenceAlertsAnnotationImpactAndStaleWork(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-07 12:00:00 UTC'));
        $this->app->instance(Clock::class, $clock);
        $http = new FakeHttpClient();
        $this->app->instance(HttpClient::class, $http);
        $this->app->instance(HostResolver::class, new FakeHostResolver());
        $owner = $this->createUser('watch@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $browser = $this->browserFor($owner['user']->email);
        $uuid = $this->createProject($browser, 'Shop', 'shop.test');
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($owner['workspace']->id, $uuid);
        self::assertNotNull($project);
        $base = '/projects/' . $uuid . '/page-monitoring';
        $html = (string) $browser->get($base)->getBody();
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertSame(419, $browser->post($base, ['url' => 'https://shop.test/Page'], withCsrf: false)->getStatusCode());
        $browser->get($base);
        foreach (['http://shop.test/Page', 'https://evil.test/', 'https://shop.test:443/', 'https://name:pass@shop.test/', 'https://shop.test/#fragment'] as $bad) {
            self::assertSame(422, $browser->post($base, ['url' => $bad])->getStatusCode());
            $browser->get($base);
        }
        $url = 'https://shop.test/Page?Q=%CE%A9';
        self::assertSame(303, $browser->post($base, ['url' => $url])->getStatusCode());
        $data = $this->app->make(PageMonitoringRepository::class);
        $service = $this->app->make(PageMonitoringService::class);
        self::assertNull($data->find($project->id, strtolower($url)));
        self::assertSame([], $http->requests, 'Browser reads/writes never fetch the website.');
        $registry = $this->app->make(JobRegistry::class);
        $registry->handler('page_monitoring.schedule')->handle([], new JobContext(Uuid::v7(), 'page_monitoring.schedule', 1, 3, static fn(): bool => true, time() + 120));
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE type = 'page_monitoring.check'"));
        $service->schedule();
        self::assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE type = 'page_monitoring.check'"));
        $watch = $data->find($project->id, $url);
        self::assertNotNull($watch);
        $generation = (string) $watch['generation'];
        $http->on('GET', 'https://shop.test/robots.txt', static fn(): HttpResponse => new HttpResponse(404, ''));
        $http->on('GET', $url, static fn(): HttpResponse => new HttpResponse(200, '<title>Original Ω</title><h1>Original</h1><link rel="canonical" href="/Page">', headers: ['content-type' => 'text/html']));
        $job = Uuid::v7();
        $registry->handler('page_monitoring.check')->handle(['project_id' => $project->id, 'workspace_id' => $project->workspaceId, 'public_id' => $uuid, 'url' => $url, 'generation' => $generation], new JobContext($job, 'page_monitoring.check', 1, 3, static fn(): bool => true, time() + 120));
        self::assertSame(0, $data->countChanges($project->id));
        self::assertNotNull($data->find($project->id, $url)['snapshot'] ?? null);
        $http->on('GET', $url, static fn(): HttpResponse => new HttpResponse(200, '<title>Changed &lt;script&gt; Ω</title><h1>Changed</h1><link rel="canonical" href="/other">', headers: ['content-type' => 'text/html', 'x-robots-tag' => 'noindex']));
        $service->run($project->id, $project->workspaceId, $uuid, $url, $generation, $job);
        self::assertSame(0, $data->countChanges($project->id), 'Replayed successful jobs cannot replace the baseline.');
        $clock->advance('+1 hour');
        $service->run($project->id, $project->workspaceId, $uuid, $url, $generation, Uuid::v7());
        self::assertSame(1, $data->countChanges($project->id));
        $change = $data->changes($project->id)[0];
        $events = $this->app->make(AlertDetector::class)->project($project->id, $uuid, 'Shop');
        $pageEvents = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === AlertType::PageChange));
        self::assertCount(1, $pageEvents);
        self::assertStringContainsString((string) $change['public_id'], $pageEvents[0]['fingerprint']);
        self::assertSame(1, $this->app->make(AlertService::class)->scan());
        self::assertSame(0, $this->app->make(AlertService::class)->scan(), 'The same persisted change only creates one alert.');
        $html = (string) $browser->get($base)->getBody();
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));
        $annotationPath = $base . '/' . (string) $change['public_id'] . '/annotation';
        $response = $browser->post($annotationPath, ['date' => '2026-10-07']);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('/impact', $response->getHeaderLine('Location'));
        self::assertSame(200, $browser->get($response->getHeaderLine('Location'))->getStatusCode());
        $browser->get($base);
        $again = $browser->post($annotationPath, ['date' => '2026-10-07']);
        self::assertSame($response->getHeaderLine('Location'), $again->getHeaderLine('Location'), 'Repeated annotation action is idempotent.');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM seo_annotations WHERE project_id = ?', [$project->id]));
        $http->on('GET', $url, static fn(): HttpResponse => new HttpResponse(200, 'partial', headers: ['content-type' => 'text/html'], truncated: true));
        $service->run($project->id, $project->workspaceId, $uuid, $url, $generation, Uuid::v7());
        self::assertSame(1, $data->countChanges($project->id));
        self::assertNotNull($data->find($project->id, $url)['error'] ?? null);
        $retained = PageSnapshot::decode((string) ($data->find($project->id, $url)['snapshot'] ?? ''));
        self::assertTrue($retained->values['noindex']);
        $http->on('GET', 'https://shop.test/robots.txt', static fn(): HttpResponse => new HttpResponse(200, "User-agent: *\nDisallow: /\n"));
        $requestsBeforeBlock = count($http->requests);
        $service->run($project->id, $project->workspaceId, $uuid, $url, $generation, Uuid::v7());
        self::assertCount($requestsBeforeBlock + 1, $http->requests, 'Robots blocking prevents the page fetch.');
        self::assertSame(1, $data->countChanges($project->id));
        $clock->advance('+7 days');
        $service->schedule();
        $service->schedule();
        self::assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE type = 'page_monitoring.check'"), 'A due weekly check is queued once with a valid idempotency key.');
        $browser = $this->browserFor($owner['user']->email);
        $browser->get($base);
        self::assertSame(303, $browser->post($base, ['url' => $url, 'action' => 'unwatch'])->getStatusCode());
        $requests = count($http->requests);
        $service->run($project->id, $project->workspaceId, $uuid, $url, $generation, Uuid::v7());
        self::assertCount($requests, $http->requests);
        self::assertSame(1, $data->countChanges($project->id), 'Stopping a watch retains detected history.');
        $browser->get($base);
        $browser->post($base, ['url' => $url]);
        $service->run($project->id, $project->workspaceId, $uuid, $url, $generation, Uuid::v7());
        self::assertCount($requests, $http->requests, 'Old generations cannot affect a newly added watch.');
        $clock->advance('+7 days');
        $service->schedule();
        self::assertSame(4, (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE type = 'page_monitoring.check'"), 'Watching again reads the page right away (ADR-039), then weekly.');
    }

    public function testViewerForeignProjectCapacityAndDomainChanges(): void
    {
        $owner = $this->createUser('owner@watch.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $browser = $this->browserFor($owner['user']->email);
        $uuid = $this->createProject($browser, 'Shop', 'shop.test');
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($owner['workspace']->id, $uuid);
        self::assertNotNull($project);
        $base = '/projects/' . $uuid . '/page-monitoring';
        $data = $this->app->make(PageMonitoringRepository::class);
        for ($i = 0; $i < 50; ++$i) {
            $data->watch($project, 'https://shop.test/' . $i);
        }
        $browser->get($base);
        self::assertSame(409, $browser->post($base, ['url' => 'https://shop.test/overflow'])->getStatusCode());
        self::assertCount(50, $data->watches($project));
        $viewer = $this->createUser('viewer@watch.test');
        $this->addMember($owner['workspace'], $viewer['user'], 'viewer');
        $this->db->execute('INSERT INTO project_members (project_id, user_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$project->id, $viewer['user']->id]);
        $read = $this->browserFor($viewer['user']->email);
        self::assertSame(200, $read->get($base)->getStatusCode());
        self::assertSame(403, $read->post($base, ['url' => 'https://shop.test/0', 'action' => 'unwatch'])->getStatusCode());
        $rival = $this->createUser('rival@watch.test', 'Rival');
        $foreign = $this->browserFor($rival['user']->email);
        self::assertSame(404, $foreign->get($base)->getStatusCode());
        $browser->get($base);
        self::assertSame(404, $browser->post($base . '/' . Uuid::v7() . '/annotation', ['date' => '2026-10-07'])->getStatusCode());
        $this->db->execute("UPDATE projects SET primary_domain = 'new.test' WHERE id = ?", [$project->id]);
        $this->app->make(PageMonitoringService::class)->schedule();
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM jobs WHERE type = 'page_monitoring.check'"));
        self::assertNotNull($data->find($project->id, 'https://shop.test/0')['error'] ?? null);
        $browser->get($base);
        self::assertSame(303, $browser->post($base, ['url' => 'https://shop.test/0', 'action' => 'unwatch'])->getStatusCode());
        self::assertCount(49, $data->watches($project), 'Old-domain pages can still be removed from the watchlist.');
        $watch = $data->find($project->id, 'https://shop.test/1');
        self::assertNotNull($watch);
        $this->db->execute('UPDATE workspaces SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$project->workspaceId]);
        self::assertSame([], $data->due('2030-01-01 00:00:00'));
        self::assertNull($data->find($project->id, 'https://shop.test/1'));
        $data->save($project->id, 'https://shop.test/1', (string) $watch['generation'], new PageSnapshot(['status' => 200]), '2026-10-07 12:00:00', Uuid::v7());
        self::assertNull($this->db->fetchValue('SELECT snapshot FROM page_watches WHERE project_id = ? AND url_hash = UNHEX(?)', [$project->id, hash('sha256', 'https://shop.test/1')]), 'A workspace removed during a check cannot receive new evidence.');
    }

    public function testAlertLinksResolveChangesBeyondTheFirstHistoryPage(): void
    {
        $owner = $this->createUser('history@watch.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $browser = $this->browserFor($owner['user']->email);
        $uuid = $this->createProject($browser, 'Shop', 'shop.test');
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($owner['workspace']->id, $uuid);
        self::assertNotNull($project);
        $base = '/projects/' . $uuid . '/page-monitoring';
        $before = new PageSnapshot(['html' => true, 'status' => 200, 'title' => 'Before']);
        $after = new PageSnapshot(['html' => true, 'status' => 200, 'title' => 'After']);
        $oldest = Uuid::v7();
        for ($i = 0; $i < 26; ++$i) {
            $this->db->execute('INSERT INTO page_watch_changes (public_id, project_id, url, before_snapshot, after_snapshot, before_at, detected_at, significant) VALUES (?, ?, ?, ?, ?, ?, ?, 0)', [$i === 0 ? $oldest : Uuid::v7(), $project->id, 'https://shop.test/history-' . $i, $before->encode(), $after->encode(), '2026-10-01 12:00:00', $i === 0 ? '2026-10-02 12:00:00' : '2026-10-07 12:00:00']);
        }
        $html = (string) $browser->get($base)->getBody();
        self::assertStringNotContainsString('https://shop.test/history-0', $html);
        $response = $browser->get($base . '?change=' . $oldest);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('https://shop.test/history-0', (string) $response->getBody());
        self::assertStringContainsString('Show all detected changes', (string) $response->getBody());
        self::assertSame(404, $browser->get($base . '?change=' . Uuid::v7())->getStatusCode());
        self::assertStringContainsString('https://shop.test/history-0', (string) $browser->get($base . '?page=2')->getBody());
        $csv = $browser->get($base . '?format=csv&page=2');
        self::assertSame(200, $csv->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $csv->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $csv->getHeaderLine('Cache-Control'));
        self::assertSame('nosniff', $csv->getHeaderLine('X-Content-Type-Options'));
        self::assertStringContainsString('shop-page-changes-history.csv', $csv->getHeaderLine('Content-Disposition'));
        self::assertSame(26, substr_count((string) $csv->getBody(), 'https://shop.test/history-'));
        $selected = (string) $browser->get($base . '?format=csv&change=' . $oldest)->getBody();
        self::assertSame(1, substr_count($selected, 'https://shop.test/history-'));
        self::assertStringContainsString('https://shop.test/history-0', $selected);
        self::assertSame(404, $browser->get($base . '?format=csv&change=' . Uuid::v7())->getStatusCode());
        self::assertSame(422, $browser->get($base . '?format=csv&change[]=invalid')->getStatusCode());
    }

    public function testWatchReaderForMcpIsTheRepositoryAndStaysInsideTheWorkspace(): void
    {
        $owner = $this->createUser('reader@watch.test', 'Acme');
        $rival = $this->createUser('reader@rival.test', 'Rival');
        self::assertNotNull($owner['workspace']);
        self::assertNotNull($rival['workspace']);
        $uuid = $this->createProject($this->browserFor($owner['user']->email), 'Shop', 'shop.test');
        $rivalUuid = $this->createProject($this->browserFor($rival['user']->email), 'Rival', 'rival.test');
        $projects = $this->app->make(ProjectRepository::class);
        $project = $projects->findInWorkspace($owner['workspace']->id, $uuid);
        $rivalProject = $projects->findInWorkspace($rival['workspace']->id, $rivalUuid);
        self::assertNotNull($project);
        self::assertNotNull($rivalProject);
        $reader = $this->app->make(\SeoControlCenter\Modules\PageMonitoring\PageWatchReader::class);
        self::assertInstanceOf(PageMonitoringRepository::class, $reader);
        $before = new PageSnapshot(['html' => true, 'status' => 200, 'noindex' => false]);
        $after = new PageSnapshot(['html' => true, 'status' => 200, 'noindex' => true]);
        $id = Uuid::v7();
        $this->db->execute('INSERT INTO page_watch_changes (public_id, project_id, url, before_snapshot, after_snapshot, before_at, detected_at, significant) VALUES (?, ?, ?, ?, ?, ?, ?, 1)', [$id, $project->id, 'https://shop.test/a', $before->encode(), $after->encode(), '2026-10-01 12:00:00', '2026-10-07 12:00:00']);

        self::assertSame(1, $reader->countHistory($project, '', 'significant'));
        self::assertSame(0, $reader->countHistory($project, '', 'minor'));
        self::assertSame($id, $reader->history($project, 'shop.test/a', 'all', 1)[0]['public_id']);
        self::assertNotNull($reader->change($project, $id));
        self::assertNull($reader->change($rivalProject, $id), 'Another workspace cannot read the change.');
        self::assertSame(0, $reader->countHistory($rivalProject, '', 'all'));
        self::assertSame([], $reader->watches($rivalProject));
    }

    public function testHistoryFiltersPreserveLiteralSearchPaginationAndExport(): void
    {
        $owner = $this->createUser('filters@watch.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $browser = $this->browserFor($owner['user']->email);
        $uuid = $this->createProject($browser, 'Shop', 'shop.test');
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($owner['workspace']->id, $uuid);
        self::assertNotNull($project);
        $base = '/projects/' . $uuid . '/page-monitoring';
        $before = new PageSnapshot(['html' => true, 'status' => 200, 'title' => 'Before']);
        $after = new PageSnapshot(['html' => true, 'status' => 200, 'title' => 'After']);
        $textId = Uuid::v7();
        for ($i = 0; $i < 28; ++$i) {
            $this->db->execute('INSERT INTO page_watch_changes (public_id, project_id, url, before_snapshot, after_snapshot, before_at, detected_at, significant) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [$i === 27 ? $textId : Uuid::v7(), $project->id, 'https://shop.test/literal%_!/' . $i, $before->encode(), $after->encode(), '2026-10-01 12:00:00', '2026-10-07 12:00:00', $i === 27 ? 0 : 1]);
        }
        $this->db->execute('INSERT INTO page_watch_changes (public_id, project_id, url, before_snapshot, after_snapshot, before_at, detected_at, significant) VALUES (?, ?, ?, ?, ?, ?, ?, 1)', [Uuid::v7(), $project->id, 'https://shop.test/literalXYZ/decoy', $before->encode(), $after->encode(), '2026-10-01 12:00:00', '2026-10-07 12:00:00']);
        $query = '?q=literal%25_%21&kind=significant';
        $html = (string) $browser->get($base . $query)->getBody();
        self::assertStringContainsString('27 matching changes', $html);
        self::assertStringContainsString('q=literal%25_%21&amp;kind=significant&amp;page=2', $html);
        self::assertStringContainsString('format=csv&amp;q=literal%25_%21&amp;kind=significant', $html);
        self::assertStringNotContainsString('literalXYZ/decoy', $html);
        self::assertSame(25, substr_count($html, 'class="brief-url"'));
        $page = (string) $browser->get($base . $query . '&page=999')->getBody();
        self::assertStringContainsString('page 2 of 2', $page);
        self::assertSame(2, substr_count($page, 'class="brief-url"'));
        $csv = (string) $browser->get($base . $query . '&format=csv&page=2')->getBody();
        self::assertSame(27, substr_count($csv, 'https://shop.test/literal%_!/'));
        self::assertStringNotContainsString('decoy', $csv);
        $text = (string) $browser->get($base . '?kind=text&format=csv')->getBody();
        self::assertSame(1, substr_count($text, 'https://shop.test/'));
        self::assertStringContainsString($textId, $text);
        self::assertStringContainsString($textId, (string) $browser->get($base . $query . '&format=csv&change=' . $textId)->getBody(), 'Direct selection takes precedence over history filters.');
        self::assertStringContainsString('Page text changed', (string) $browser->get($base . $query . '&change=' . $textId)->getBody());
        self::assertStringContainsString('No changes match these filters', (string) $browser->get($base . '?q=missing')->getBody());
        foreach (['?q[]=bad', '?kind[]=text', '?kind=unknown', '?q=' . str_repeat('x', 2049)] as $invalid) {
            self::assertSame(422, $browser->get($base . $invalid)->getStatusCode());
            self::assertSame(422, $browser->get($base . $invalid . '&format=csv')->getStatusCode());
        }
        $viewer = $this->createUser('viewer@filters.test');
        $this->addMember($owner['workspace'], $viewer['user'], 'viewer');
        $this->db->execute('INSERT INTO project_members (project_id, user_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$project->id, $viewer['user']->id]);
        self::assertSame(200, $this->browserFor($viewer['user']->email)->get($base . $query)->getStatusCode());
        $rival = $this->createUser('rival@filters.test', 'Rival');
        self::assertSame(404, $this->browserFor($rival['user']->email)->get($base . $query . '&format=csv')->getStatusCode());
        $data = $this->app->make(PageMonitoringRepository::class);
        self::assertCount(28, $data->changes($project->id, 1, '2026-10-01 00:00:00'), 'Significant alert reads retain their existing scope.');
    }

    public function testChangeExportIsBoundedReadableAndTenantScoped(): void
    {
        $owner = $this->createUser('export@watch.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $browser = $this->browserFor($owner['user']->email);
        $uuid = $this->createProject($browser, 'Shop', 'shop.test');
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($owner['workspace']->id, $uuid);
        self::assertNotNull($project);
        $base = '/projects/' . $uuid . '/page-monitoring';
        $empty = (string) $browser->get($base . '?format=csv')->getBody();
        self::assertStringContainsString('Change ID', $empty);
        self::assertSame(1, substr_count($empty, "\r\n"));
        $before = new PageSnapshot(['html' => true, 'status' => 200, 'title' => '=SUM(1,2)', 'noindex' => false]);
        $after = new PageSnapshot(['html' => true, 'status' => 200, 'title' => "Changed Ω, \"quoted\"\nsecond line", 'noindex' => true]);
        for ($i = 0; $i < 501; ++$i) {
            $this->db->execute('INSERT INTO page_watch_changes (public_id, project_id, url, before_snapshot, after_snapshot, before_at, detected_at, significant) VALUES (?, ?, ?, ?, ?, ?, ?, 1)', [Uuid::v7(), $project->id, 'https://shop.test/export-' . $i, $before->encode(), $after->encode(), '2026-10-01 12:00:00', $i === 0 ? '2026-10-02 12:00:00' : '2026-10-07 12:00:00']);
        }
        $data = $this->app->make(PageMonitoringRepository::class);
        self::assertCount(500, $data->exportChanges($project));
        $csv = (string) $browser->get($base . '?format=csv')->getBody();
        self::assertSame(1000, substr_count($csv, 'https://shop.test/export-'));
        self::assertStringNotContainsString('https://shop.test/export-0,', $csv);
        self::assertStringContainsString("'=SUM(1,2)", $csv);
        self::assertStringContainsString('Changed Ω, ""quoted""', $csv);
        self::assertStringContainsString('noindex,No,Yes', $csv);
        self::assertStringContainsString('2026-10-01 12:00:00', $csv);
        $viewer = $this->createUser('reader@export.test');
        $this->addMember($owner['workspace'], $viewer['user'], 'viewer');
        $this->db->execute('INSERT INTO project_members (project_id, user_id, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$project->id, $viewer['user']->id]);
        self::assertSame(200, $this->browserFor($viewer['user']->email)->get($base . '?format=csv')->getStatusCode());
        $rival = $this->createUser('foreign@export.test', 'Rival');
        $rivalBrowser = $this->browserFor($rival['user']->email);
        self::assertSame(404, $rivalBrowser->get($base . '?format=csv')->getStatusCode());
        $foreignId = $this->createProject($rivalBrowser, 'Foreign', 'foreign.test');
        self::assertNotNull($rival['workspace']);
        $foreignProject = $this->app->make(ProjectRepository::class)->findInWorkspace($rival['workspace']->id, $foreignId);
        self::assertNotNull($foreignProject);
        $foreignChange = Uuid::v7();
        $this->db->execute('INSERT INTO page_watch_changes (public_id, project_id, url, before_snapshot, after_snapshot, before_at, detected_at, significant) VALUES (?, ?, ?, ?, ?, ?, ?, 1)', [$foreignChange, $foreignProject->id, 'https://foreign.test/secret', $before->encode(), $after->encode(), '2026-10-01 12:00:00', '2026-10-08 12:00:00']);
        self::assertSame(404, $browser->get($base . '?format=csv&change=' . $foreignChange)->getStatusCode());
        self::assertStringNotContainsString('foreign.test/secret', (string) $browser->get($base . '?format=csv')->getBody());
        self::assertStringContainsString('Export retained history as CSV', (string) $browser->get($base)->getBody());
    }
}

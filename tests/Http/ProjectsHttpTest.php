<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

final class ProjectsHttpTest extends TestCase
{
    public function testOverviewShowsRecordedForecastMetricsWithoutFillingMissingDays(): void
    {
        $s = new Scenario();
        $store = $s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class, $store);
        $store->daily[$s->acmeProject->id] = [
            '2026-09-20' => new \SeoControlCenter\Domain\SearchConsole\Metrics(12, 200, 8.5),
            '2026-09-22' => new \SeoControlCenter\Domain\SearchConsole\Metrics(0, 0, 0.0),
        ];
        $response = $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId);
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Performance prognosis', $html);
        self::assertStringContainsString('data-metric="position"', $html);
        self::assertMatchesRegularExpression('/data-days="([^"]+)"/', $html);
        self::assertSame(1, preg_match('/data-days="([^"]+)"/', $html, $matches));
        $days = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['2026-09-20', '2026-09-22'], array_column($days, 'date'));
        self::assertSame(6, $days[0]['ctr']);
        self::assertNull($days[1]['position']);
        self::assertStringNotContainsString('Import now', $html);
        self::assertSame(1, preg_match('/data-preliminary="([^"]*)"/', $html, $matches));
        self::assertSame([], json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Latest days, not final yet', $html, 'No legend entry without preliminary days.');
    }

    public function testOverviewPassesPreliminaryDaysAfterTheLastFinalDayToThePrognosis(): void
    {
        $s = new Scenario();
        $store = $s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class);
        $extras = $s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchConsoleExtras::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance::class, $store);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemorySearchConsoleExtras::class, $extras);
        $store->daily[$s->acmeProject->id] = ['2026-09-20' => new \SeoControlCenter\Domain\SearchConsole\Metrics(12, 200, 8.5)];
        $extras->replaceFreshDays($s->acmeProject->id, [
            new \SeoControlCenter\Domain\SearchConsole\FreshDay('2026-09-20', new \SeoControlCenter\Domain\SearchConsole\Metrics(11, 190, 8.4), true),
            new \SeoControlCenter\Domain\SearchConsole\FreshDay('2026-09-21', new \SeoControlCenter\Domain\SearchConsole\Metrics(10, 200, 8.0), true),
            new \SeoControlCenter\Domain\SearchConsole\FreshDay('2026-09-22', new \SeoControlCenter\Domain\SearchConsole\Metrics(3, 0, 0.0), false),
        ]);
        $html = (string) $s->loggedIn($s->viewer)->get('/projects/' . $s->acmeProject->publicId)->getBody();

        self::assertSame(1, preg_match('/data-preliminary="([^"]*)"/', $html, $matches));
        $preliminary = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['2026-09-21', '2026-09-22'], array_column($preliminary, 'date'), 'Final data wins for 2026-09-20.');
        self::assertSame([true, false], array_column($preliminary, 'complete'));
        self::assertSame(5, $preliminary[0]['ctr']);
        self::assertNull($preliminary[1]['position'], 'No position without impressions.');
        self::assertSame(1, preg_match('/data-days="([^"]+)"/', $html, $days));
        self::assertSame(['2026-09-20'], array_column(json_decode(html_entity_decode($days[1], ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR), 'date'), 'Preliminary days never enter the recorded series.');
        self::assertStringContainsString('Latest days, not final yet', $html);
    }

    public function testOwnerCreatesProject(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/projects/new');

        $response = $browser->post('/projects', ['name' => 'New Site', 'primary_domain' => 'https://www.NewSite.test/path', 'timezone' => 'Europe/Berlin']);

        self::assertSame(303, $response->getStatusCode());
        self::assertMatchesRegularExpression('#^/projects/[0-9a-f-]{36}$#', $response->getHeaderLine('Location'));

        $show = (string) $browser->get($response->getHeaderLine('Location'))->getBody();
        self::assertStringContainsString('Project created.', $show);
        self::assertStringContainsString('www.newsite.test', $show);
        self::assertStringContainsString('value="Europe/Berlin" selected', (string) $browser->get($response->getHeaderLine('Location') . '/edit')->getBody(), 'Settings show the time zone.');

        $events = $s->audit()->events;
        $event = end($events);
        self::assertNotFalse($event);
        self::assertSame('project.created', $event->action);
        self::assertSame($s->acme->id, $event->workspaceId);
        self::assertSame($s->owner->id, $event->actorUserId);
    }

    public function testValidationErrorsAreShownAndNothingIsCreated(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/projects/new');

        $response = $browser->post('/projects', ['name' => '', 'primary_domain' => '127.0.0.1', 'timezone' => 'Mars/Base']);
        $html = (string) $response->getBody();

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('Enter a project name.', $html);
        self::assertStringContainsString('Enter a valid domain name', $html);
        self::assertStringContainsString('Choose a valid time zone.', $html);
        self::assertNotContains('project.created', $s->audit()->actions());
    }

    public function testUserInputIsEscaped(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/projects/new');
        $location = $browser->post('/projects', ['name' => '<img src=x onerror=alert(1)>', 'primary_domain' => 'xss.test'])->getHeaderLine('Location');

        $html = (string) $browser->get($location)->getBody();
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function testEditAndDeleteWithConfirmation(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->admin);
        $url = '/projects/' . $s->acmeProject->publicId;

        $browser->get($url . '/edit');
        self::assertSame(303, $browser->post($url, ['name' => 'Renamed', 'primary_domain' => 'shop.acme.test', 'timezone' => 'UTC'])->getStatusCode());
        self::assertStringContainsString('Renamed', (string) $browser->get($url)->getBody());

        // Wrong confirmation: nothing deleted.
        $browser->post($url . '/delete', ['confirm_name' => 'nope']);
        self::assertSame(200, $browser->get($url)->getStatusCode());

        $browser->post($url . '/delete', ['confirm_name' => 'Renamed']);
        self::assertSame(404, $browser->get($url)->getStatusCode());
        self::assertContains('project.deleted', $s->audit()->actions());
    }

    public function testMembersSeeOnlyAssignedProjects(): void
    {
        $s = new Scenario();
        $html = (string) $s->loggedIn($s->member)->get('/projects')->getBody();

        self::assertStringContainsString('Acme Shop', $html);
        self::assertStringNotContainsString('Acme Blog', $html);
        self::assertStringNotContainsString('Rival Site', $html);
    }

    public function testViewerCannotCreateOrEdit(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->viewer);
        $url = '/projects/' . $s->acmeProject->publicId;

        self::assertSame(403, $browser->get('/projects/new')->getStatusCode());
        self::assertSame(403, $browser->get($url . '/edit')->getStatusCode());
        $browser->get($url);
        self::assertSame(403, $browser->post('/projects', ['name' => 'X', 'primary_domain' => 'x.test'])->getStatusCode());
        $browser->get($url); // error pages carry no form token; reload a normal page
        self::assertSame(403, $browser->post($url, ['name' => 'X', 'primary_domain' => 'x.test'])->getStatusCode());
        self::assertStringNotContainsString('Delete project', (string) $browser->get($url)->getBody());
    }

    public function testMemberCannotDelete(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->member);
        $url = '/projects/' . $s->acmeProject->publicId;
        $browser->get($url);

        self::assertSame(403, $browser->post($url . '/delete', ['confirm_name' => 'Acme Shop'])->getStatusCode());
        self::assertSame(200, $browser->get($url)->getStatusCode());
    }

    public function testAuditLogIsVisibleToAdminsOnly(): void
    {
        $s = new Scenario();
        self::assertSame(200, $s->loggedIn($s->admin)->get('/audit')->getStatusCode());
        self::assertSame(403, $s->loggedIn($s->member)->get('/audit')->getStatusCode());
    }

    public function testMembersPageListsWorkspaceMembersOnly(): void
    {
        $s = new Scenario();
        $html = (string) $s->loggedIn($s->viewer)->get('/workspace/members')->getBody();

        self::assertStringContainsString('owner@acme.test', $html);
        self::assertStringNotContainsString('owner@rival.test', $html);
    }
}

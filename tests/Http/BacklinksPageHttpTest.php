<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Backlinks\AnchorText;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Backlinks\BacklinksService;
use SeoControlCenter\Tests\Support\Fakes\FakeBacklinksProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBacklinkHistory;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Backlinks: queued lookups, stored results, the monthly history, the link
 * gap, permissions and isolation.
 */
final class BacklinksPageHttpTest extends TestCase
{
    private Scenario $s;

    private FrozenClock $clock;

    private FakeBacklinksProvider $provider;

    private InMemoryResearch $research;

    private InMemoryBacklinkHistory $history;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-03 08:00:00', new \DateTimeZone('UTC')));
        $this->s->container->instance(Clock::class, $this->clock);
        $provider = $this->s->container->get(FakeBacklinksProvider::class);
        $research = $this->s->container->get(InMemoryResearch::class);
        $history = $this->s->container->get(InMemoryBacklinkHistory::class);
        self::assertInstanceOf(FakeBacklinksProvider::class, $provider);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        self::assertInstanceOf(InMemoryBacklinkHistory::class, $history);
        $this->provider = $provider;
        $this->research = $research;
        $this->history = $history;
        $competitors = $this->s->container->get(InMemoryCompetitors::class);
        self::assertInstanceOf(InMemoryCompetitors::class, $competitors);
        $competitors->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        $this->provider->profiles = [
            'shop.acme.test' => new BacklinkProfile('shop.acme.test', 30, 900, 120, 30, 4),
            'rival.test' => new BacklinkProfile('rival.test', 55, 25000, 1800, 300, 8),
        ];
        $this->provider->domains = [new ReferringDomain('blog.test', 40, 12, 2, '2025-01-01'), new ReferringDomain('zeta.test', 10, 300, 30, '2024-01-01')];
        $this->provider->new = [new ReferringDomain('fresh.test', 22, 1, 0, '2026-09-28')];
        $this->provider->lost = [new ReferringDomain('gone.test', 15, 2, 0, '2024-02-02', '2026-09-30')];
        $this->provider->anchors = [new AnchorText('Acme Shop', 300, 90), new AnchorText('', 12)];
        $this->provider->gap = [new ReferringDomain('mag.test', 62, 5, 1, '2023-01-01', null, 1)];
    }

    public function testLookupsAreStoredAndShown(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Update profiles', $page);
        self::assertStringContainsString('Not fetched yet.', $page);

        foreach (BacklinksService::KINDS as $kind) {
            $owner->get($this->url());
            self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => $kind])->getStatusCode(), $kind);
            $this->service()->run($this->s->acmeProject->id, $this->s->acme->id, $kind, false);
        }
        self::assertCount(8, $this->history->queued);
        self::assertSame([['shop.acme.test']], $this->provider->calls[0]['args']);
        self::assertSame([['rival.test']], $this->provider->calls[1]['args']);
        self::assertSame(['shop.acme.test', '2026-09-03', false], $this->provider->calls[3]['args'], 'new: the last 30 days');
        self::assertSame(['shop.acme.test', '2026-09-03', true], $this->provider->calls[4]['args']);
        self::assertSame(['shop.acme.test', ['rival.test']], $this->provider->calls[6]['args']);

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('shop.acme.test <span class="badge badge-ok">You</span>', $page);
        self::assertStringContainsString('1,800', $page);
        self::assertStringContainsString('25 %', $page, 'nofollow share');
        self::assertStringContainsString('blog.test', $page);
        self::assertStringContainsString('fresh.test', $page);
        self::assertStringContainsString('2026-09-30', $page);
        self::assertStringContainsString('mag.test', $page);
        self::assertStringContainsString('1 / 1', $page, 'links to one of one competitor');
        self::assertStringContainsString('Acme Shop', $page);
        self::assertStringContainsString('(empty, e.g. an image link)', $page);
        self::assertContains('backlinks.requested', $this->s->audit()->actions());

        $sorted = (string) $owner->get($this->url() . '?sort=backlinks&dir=desc')->getBody();
        self::assertLessThan(strpos($sorted, '<td class="kw-cell">blog.test</td>'), strpos($sorted, '<td class="kw-cell">zeta.test</td>'), 'most backlinks first');
    }

    public function testMonthlyChecksBuildAHistory(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/monthly', ['monthly' => '1'])->getStatusCode());
        self::assertStringContainsString('Stop monthly check', (string) $owner->get($this->url())->getBody());

        self::assertSame(1, $this->service()->startDue());
        self::assertSame(0, $this->service()->startDue(), 'already queued');
        $this->service()->run($this->s->acmeProject->id, $this->s->acme->id, 'bl_profiles', false);
        self::assertSame(0, $this->service()->startDue(), 'checked today');

        $this->clock->advance('+30 days');
        $this->provider->profiles['shop.acme.test'] = new BacklinkProfile('shop.acme.test', 33, 1000, 135, 30, 4);
        self::assertSame(1, $this->service()->startDue());
        $this->service()->run($this->s->acmeProject->id, $this->s->acme->id, 'bl_profiles', false);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Your profile over time', $page);
        self::assertStringContainsString('with the change since 2026-10-03', $page);
        self::assertStringContainsString('<span class="delta delta-good">+15</span>', $page, '135 against 120 referring domains');
    }

    public function testFailuresGapWithoutCompetitorsAndPermissions(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url() . '/fetch', ['kind' => 'bl_domains']);
        $this->provider->fail = new IntegrationException('Backlinks API is not activated.', false);
        $this->service()->run($this->s->acmeProject->id, $this->s->acme->id, 'bl_domains', false);
        self::assertStringContainsString('The lookup failed: Backlinks API is not activated.', (string) $owner->get($this->url())->getBody());

        $owner->get($this->url());
        self::assertSame(400, $owner->post($this->url() . '/fetch', ['kind' => 'nonsense'])->getStatusCode());

        $viewer = $this->s->loggedIn($this->s->viewer);
        $page = (string) $viewer->get($this->url())->getBody();
        self::assertStringNotContainsString('Update profiles', $page);
        self::assertStringNotContainsString('Check every month', $page);
        $viewer->get($this->url());
        self::assertSame(403, $viewer->post($this->url() . '/fetch', ['kind' => 'bl_profiles'])->getStatusCode());
        $viewer->get($this->url());
        self::assertSame(403, $viewer->post($this->url() . '/monthly', ['monthly' => '1'])->getStatusCode());

        $rival = $this->s->loggedIn($this->s->rivalOwner);
        self::assertSame(404, $rival->get($this->url())->getStatusCode());
        $rival->get('/');
        self::assertSame(404, $rival->post($this->url() . '/fetch', ['kind' => 'bl_profiles'])->getStatusCode());

        // The link gap needs competitors.
        $other = '/projects/' . $this->s->acmeOther->publicId . '/backlinks';
        self::assertStringContainsString('to find domains that link to them but not to you', (string) $owner->get($other)->getBody());
        self::assertSame(409, $owner->post($other . '/fetch', ['kind' => 'bl_gap'])->getStatusCode());
        self::assertNull($this->research->find($this->s->acmeOther->id, 'bl_gap'));
    }

    public function testLostLinkMonitoringRequiresOptInAndRunsWeekly(): void
    {
        self::assertSame(0, $this->service()->startDue());
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/monitoring', ['monitor_lost' => '1'])->getStatusCode());
        self::assertTrue($this->history->monitorsLost($this->s->acmeProject->id));
        self::assertContains('backlinks.lost_monitoring_saved', $this->s->audit()->actions());
        self::assertSame(2, $this->service()->startDue());
        self::assertSame(0, $this->service()->startDue(), 'Queued monitoring must not queue again.');
        foreach (['bl_lost_links', 'bl_lost'] as $kind) {
            $this->service()->run($this->s->acmeProject->id, $this->s->acme->id, $kind, false);
        }
        self::assertSame(0, $this->service()->startDue());
        $this->clock->advance('+6 days');
        self::assertSame(0, $this->service()->startDue());
        $this->clock->advance('+1 day');
        self::assertSame(2, $this->service()->startDue());
        foreach (['bl_lost_links', 'bl_lost'] as $kind) {
            $this->service()->run($this->s->acmeProject->id, $this->s->acme->id, $kind, false);
        }
        $owner->get($this->url());
        $owner->post($this->url() . '/monitoring', ['monitor_lost' => '0']);
        $this->clock->advance('+7 days');
        self::assertSame(0, $this->service()->startDue());
    }

    public function testDisablingMonitoringStopsScheduledBacklinksButAllowsManualChecks(): void
    {
        $project = $this->s->acmeProject;
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/monthly', ['monthly' => '1'])->getStatusCode());
        self::assertSame(303, $owner->post($this->url() . '/monitoring', ['monitor_lost' => '1'])->getStatusCode());
        self::assertSame(3, $this->service()->startDue());
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/monthly', ['monthly' => '0'])->getStatusCode());
        self::assertSame(303, $owner->post($this->url() . '/monitoring', ['monitor_lost' => '0'])->getStatusCode());
        foreach (['bl_profiles', 'bl_lost_links', 'bl_lost'] as $kind) {
            $this->service()->run($project->id, $project->workspaceId, $kind, false);
            self::assertSame('failed', $this->research->find($project->id, $kind)?->status);
        }
        self::assertSame([], $this->provider->calls, 'Disabled monitoring must not spend on queued scheduled work.');
        self::assertSame(0, $this->service()->startDue());
        foreach (['bl_profiles', 'bl_lost_links', 'bl_lost'] as $kind) {
            $owner->get($this->url());
            self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => $kind])->getStatusCode());
            $this->service()->run($project->id, $project->workspaceId, $kind, false);
        }
        self::assertCount(4, $this->provider->calls, 'Manual requests remain available while monitoring is disabled.');
    }

    public function testChangedDomainCancelsQueuedBacklinkRequestsBeforeSpending(): void
    {
        $project = $this->s->acmeProject;
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'bl_profiles'])->getStatusCode());
        $owner->get('/projects/' . $project->publicId . '/edit');
        self::assertSame(303, $owner->post('/projects/' . $project->publicId, ['name' => 'Changed site', 'primary_domain' => 'new-site.test', 'timezone' => 'UTC'])->getStatusCode());
        $this->service()->run($project->id, $project->workspaceId, 'bl_profiles', false);
        self::assertSame([], $this->provider->calls, 'Queued requests for the previous domain must not spend.');
        self::assertSame('failed', $this->research->find($project->id, 'bl_profiles')?->status);
        self::assertSame([], $this->history->history($project->id, 12), 'Stale results must not enter backlink history.');

        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'bl_profiles'])->getStatusCode());
        $this->service()->run($project->id, $project->workspaceId, 'bl_profiles', false);
        self::assertCount(2, $this->provider->calls);
        self::assertSame('new-site.test', $this->provider->calls[0]['args'][0][0]);
    }

    public function testForeignJobPayloadCannotSpendForAnotherWorkspace(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url() . '/fetch', ['kind' => 'bl_links']);
        $this->service()->run($this->s->acmeProject->id, $this->s->rivalProject->workspaceId, 'bl_links', false);
        self::assertSame([], $this->provider->calls);
        self::assertTrue($this->research->find($this->s->acmeProject->id, 'bl_links')?->isQueued());
    }

    private function service(): BacklinksService
    {
        return $this->s->container->get(BacklinksService::class);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/backlinks';
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLocal;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Opportunities page: analysis on stored data, workflow, permissions and tenant isolation.
 */
final class OpportunitiesHttpTest extends TestCase
{
    private Scenario $s;

    private InMemoryOpportunities $opportunities;

    protected function setUp(): void
    {
        $this->s = new Scenario(clock: new \SeoControlCenter\Core\Support\FrozenClock(new \DateTimeImmutable('2026-10-03')));
        $opportunities = $this->s->container->get(InMemoryOpportunities::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        $searchConsole = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemoryOpportunities::class, $opportunities);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        self::assertInstanceOf(InMemorySearchPerformance::class, $searchConsole);
        $this->opportunities = $opportunities;
        $rankings->projects[$this->s->acmeProject->id] = ['workspace_id' => $this->s->acme->id, 'domain' => 'shop.acme.test'];

        $project = $this->s->acmeProject->id;
        $searchConsole->upsertDailyTotals($project, [new DailyMetrics('2026-10-01', new Metrics(10, 2000, 8.0))]);
        $searchConsole->replaceDay($project, '2026-10-01', [
            'query' => [
                new DimensionRow('weak snippet', new Metrics(4, 1000, 2.0)),   // low CTR
                new DimensionRow('deep topic', new Metrics(0, 400, 34.0)),     // content gap
                new DimensionRow('fine query', new Metrics(150, 1000, 1.0)),   // nothing to do
            ],
            'page' => [],
        ]);
    }

    public function testAnalyzeShowsExplainedOpportunitiesByPriority(): void
    {
        $browser = $this->s->loggedIn($this->s->member);
        $browser->get($this->url());

        self::assertSame(303, $browser->post($this->url() . '/analyze')->getStatusCode());
        $html = (string) $browser->get($this->url())->getBody();

        self::assertStringContainsString('Analysis finished: 2 new, 0 updated, 0 resolved.', $html);
        self::assertMatchesRegularExpression('#Improve the search snippet for “weak snippet”.*Create or expand content for “deep topic”#s', $html, 'Highest priority first.');
        self::assertMatchesRegularExpression('#class="card opportunity insight-opportunity opportunity-type-[a-z_]+ priority-band-[1-5]"#', $html, 'Each finding carries its colour band.');
        self::assertStringContainsString('aria-label="Priority colour scale"', $html);
        self::assertStringContainsString('Click-through rate', $html, 'Evidence is shown.');
        self::assertStringNotContainsString('fine query', $html);
        self::assertStringContainsString('Low click-through rate (1 open)', $html);
    }

    public function testEveryDataSourceFeedsTheAnalysis(): void
    {
        $project = $this->s->acmeProject;
        $research = $this->s->container->get(InMemoryResearch::class);
        $local = $this->s->container->get(InMemoryLocal::class);
        $competitors = $this->s->container->get(InMemoryCompetitors::class);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        self::assertInstanceOf(InMemoryLocal::class, $local);
        self::assertInstanceOf(InMemoryCompetitors::class, $competitors);
        $rival = $competitors->add($project->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        self::assertNotNull($rival);
        $this->store($research, 'ranked', [(new ResearchKeyword('grow lights', 3000, null, 25, 'commercial', 6, 'https://shop.acme.test/lights'))->toArray()]);
        $this->store($research, 'gap', [(new ResearchKeyword('seed guide', 2000, null, 40, 'informational', 2, 'https://rival.test/guide'))->toArray()], ['competitor' => 'rival.test'], $rival->publicId);
        $this->store($research, 'bl_profiles', [(new BacklinkProfile('shop.acme.test', 30, 900, 40))->toArray(), (new BacklinkProfile('rival.test', 55, 25000, 1800))->toArray()]);
        $this->store($research, 'bl_gap', [(new ReferringDomain('mag.test', 62, 5, 1, null, null, 1))->toArray()]);
        $this->store($research, 'llm', [(new AiMention('shop.acme.test', 'chat_gpt', 2))->toArray(), (new AiMention('rival.test', 'chat_gpt', 90))->toArray()]);
        $local->saveSettings($project->id, $project->workspaceId, 'Acme Shop', new LocalArea(43.85, 18.41, 13), 'en', false, $this->s->owner->id);
        $local->addKeywords($project->id, ['seed shop']);
        $local->recordRanking($project->id, $local->keywords($project->id)[0]['id'], '2026-10-03', 6, [new LocalResult(1, 'Green Valley', rating: 4.8, votes: 400)]);
        $local->recordProfile($project->id, '2026-10-03', new BusinessProfile('Acme Shop', 'Garden center', 4.6, 80, false));

        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get($this->url());
        $browser->post($this->url() . '/analyze');
        $html = (string) $browser->get($this->url())->getBody();

        foreach ([
            'Move “grow lights” from position 6 into the top 3',
            'Create a page for “seed guide” (1 competitor on page one)',
            'Build referring domains: you have 40, your competitors a median of 1,800',
            'Get a link from mag.test (links to 1 of 1 competitors)',
            'ChatGPT mentions rival.test 90 times, you 2',
            'Get into the local pack for “seed shop” (now #6)',
            'Claim your Google Business Profile',
            'Improve the search snippet for “weak snippet”',
        ] as $title) {
            self::assertStringContainsString($title, $html);
        }
        foreach (['Keyword gap', 'Link building', 'AI visibility', 'Local SEO'] as $type) {
            self::assertStringContainsString($type . ' (', $html, $type . ' is a type filter with open opportunities');
        }
    }

    public function testWorkflowDoneDismissReopenAndResolved(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get($this->url());
        $browser->post($this->url() . '/analyze');
        $weak = $this->opportunities->list($this->s->acmeProject->id, 'open', null, 10, 0)[0];

        $browser->get($this->url());
        self::assertSame(303, $browser->post($this->url() . '/' . $weak->publicId . '/status', ['status' => 'dismissed'])->getStatusCode());
        self::assertSame('dismissed', $this->opportunities->find($this->s->acmeProject->id, $weak->publicId)?->status);
        self::assertContains('opportunities.status_changed', $this->s->audit()->actions());

        // Analyzing again keeps a dismissed opportunity dismissed.
        $browser->get($this->url());
        $browser->post($this->url() . '/analyze');
        self::assertSame('dismissed', $this->opportunities->find($this->s->acmeProject->id, $weak->publicId)?->status);
        self::assertStringContainsString('Improve the search snippet for “weak snippet”', (string) $browser->get($this->url() . '?status=dismissed')->getBody());

        $browser->get($this->url() . '?status=dismissed');
        $browser->post($this->url() . '/' . $weak->publicId . '/status', ['status' => 'open']);
        self::assertSame('open', $this->opportunities->find($this->s->acmeProject->id, $weak->publicId)?->status);

        $browser->get($this->url());
        self::assertSame(400, $browser->post($this->url() . '/' . $weak->publicId . '/status', ['status' => 'resolved'])->getStatusCode(), 'Only the system resolves.');
    }

    public function testPagePlanViewsAreReadOnlyAndProjectScoped(): void
    {
        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertSame(200, $viewer->get($this->url() . '/by-page')->getStatusCode());
        self::assertSame(200, $viewer->get($this->url() . '/weekly')->getStatusCode());
        self::assertSame(200, $viewer->get($this->url() . '/outcomes')->getStatusCode());
        self::assertSame(200, $viewer->get($this->url() . '/weekly-plan')->getStatusCode());
        $response = $viewer->get($this->url() . '/page-plans');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], json_decode((string) $response->getBody(), true)['plans']);
        foreach ([$this->s->rivalOwner, $this->s->unassigned] as $user) {
            $browser = $this->s->loggedIn($user);
            self::assertSame(404, $browser->get($this->url() . '/by-page')->getStatusCode());
            self::assertSame(404, $browser->get($this->url() . '/weekly')->getStatusCode());
            self::assertSame(404, $browser->get($this->url() . '/outcomes')->getStatusCode());
            self::assertSame(404, $browser->get($this->url() . '/weekly-plan')->getStatusCode());
            self::assertSame(404, $browser->get($this->url() . '/page-plans')->getStatusCode());
        }
    }

    public function testDetailFiltersSnoozePermissionsAndForeignFindingIsolation(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url() . '/analyze');
        $row = $this->opportunities->list($this->s->acmeProject->id, 'open', null, 10, 0)[0];
        $detail = $this->url() . '/' . $row->publicId;
        $response = $owner->get($detail);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Inputs → calculation → result', (string) $response->getBody());
        self::assertSame([], AccessibilityTest::problems((string) $response->getBody(), true));
        $data = json_decode((string) $owner->get($detail . '/data')->getBody(), true);
        self::assertSame($row->publicId, $data['opportunity']['opportunity_id']);
        self::assertSame($row->priority, $data['opportunity']['priority']);
        self::assertSame(400, $owner->get($this->url() . '?min_impact=101')->getStatusCode());
        self::assertSame(400, $owner->get($this->url() . '?effort=4')->getStatusCode());
        $owner->get($detail);
        self::assertSame(400, $owner->post($detail . '/snooze', ['until' => '2026-10-01'])->getStatusCode());
        $owner->get($detail);
        self::assertSame(303, $owner->post($detail . '/snooze', ['until' => '2026-10-10'])->getStatusCode());
        $snoozed = $this->opportunities->find($this->s->acmeProject->id, $row->publicId);
        self::assertNotNull($snoozed);
        self::assertSame('snoozed', $snoozed->status);
        self::assertSame('2026-10-10', $snoozed->snoozedUntil);
        self::assertContains('opportunities.snoozed', $this->s->audit()->actions());
        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertSame(200, $viewer->get($detail)->getStatusCode());
        $viewer->get('/account/security');
        self::assertSame(403, $viewer->post($detail . '/snooze', ['until' => '2026-10-10'])->getStatusCode());
        foreach ([$this->s->rivalOwner, $this->s->unassigned] as $user) {
            $browser = $this->s->loggedIn($user);
            self::assertSame(404, $browser->get($detail)->getStatusCode());
            self::assertSame(404, $browser->get($detail . '/data')->getStatusCode());
            $browser->get('/account/security');
            self::assertSame(404, $browser->post($detail . '/snooze', ['until' => '2026-10-10'])->getStatusCode());
        }
        self::assertSame(404, $owner->get('/projects/' . $this->s->acmeOther->publicId . '/opportunities/' . $row->publicId . '/data')->getStatusCode());
    }

    public function testViewersReadOnlyAndOtherTenantsNotFound(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url() . '/analyze');
        $any = $this->opportunities->list($this->s->acmeProject->id, 'open', null, 10, 0)[0];

        $viewer = $this->s->loggedIn($this->s->viewer);
        $html = (string) $viewer->get($this->url())->getBody();
        self::assertStringContainsString('weak snippet', $html);
        self::assertStringNotContainsString('Analyze now', $html);
        self::assertStringNotContainsString('Mark done', $html);
        $viewer->get('/account/security');
        $csrf = $viewer->csrfToken();
        self::assertSame(403, $viewer->post($this->url() . '/analyze', ['_csrf' => $csrf])->getStatusCode());
        self::assertSame(403, $viewer->post($this->url() . '/' . $any->publicId . '/status', ['status' => 'done', '_csrf' => $csrf])->getStatusCode());

        foreach ([$this->s->rivalOwner, $this->s->unassigned] as $user) {
            $browser = $this->s->loggedIn($user);
            self::assertSame(404, $browser->get($this->url())->getStatusCode());
        }
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/account/security');
        self::assertSame(404, $rival->post('/projects/' . $this->s->rivalProject->publicId . '/opportunities/' . $any->publicId . '/status', ['status' => 'done'])->getStatusCode());
        self::assertSame('open', $this->opportunities->find($this->s->acmeProject->id, $any->publicId)?->status);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $params
     */
    private function store(InMemoryResearch $research, string $kind, array $rows, array $params = [], string $subject = ''): void
    {
        $research->queue($this->s->acmeProject->id, $this->s->acme->id, $kind, $subject, $params, null);
        $research->complete($this->s->acmeProject->id, $kind, $subject, $rows);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/opportunities';
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\BusinessContextRepository;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Relevance filtering before and after paid research (ADR-037): clashing
 * seeds are dropped unless confirmed, other markets need confirmation,
 * results carry a verdict with its reason, and team overrides stick.
 */
final class RelevanceResearchHttpTest extends TestCase
{
    private Scenario $s;

    private InMemoryResearch $research;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $research = $this->s->container->get(InMemoryResearch::class);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        $this->research = $research;
        $this->profile();
    }

    public function testClashingSeedsAreDroppedBeforeSpendingUnlessConfirmed(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $response = $owner->post($this->url() . '/fetch', ['kind' => 'suggestions', 'seeds' => "running shoes\nfree shoes\nshoe jobs", 'modifiers' => 'best', 'location' => '2276', 'language' => 'de']);
        self::assertSame(303, $response->getStatusCode());
        $queued = $this->research->find($this->s->acmeProject->id, 'suggestions');
        self::assertNotNull($queued);
        self::assertSame(['running shoes'], $queued->params['seeds'], 'Excluded and job seeds are never paid for.');
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Left out because they clash with your business profile', $page);
        self::assertStringContainsString('“free shoes” (contains “free”, a term your profile excludes)', html_entity_decode($page));

        $all = $owner->post($this->url() . '/fetch', ['kind' => 'ideas', 'seeds' => "free shoes\ndiy shoes", 'location' => '2276', 'language' => 'de']);
        self::assertSame(422, $all->getStatusCode());
        self::assertStringContainsString('Every seed clashes with your business profile', html_entity_decode((string) $all->getBody()));
        self::assertNull($this->research->find($this->s->acmeProject->id, 'ideas'));

        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'ideas', 'seeds' => "free shoes\ndiy shoes", 'location' => '2276', 'language' => 'de', 'confirm' => '1'])->getStatusCode());
        self::assertSame(['free shoes', 'diy shoes'], $this->research->find($this->s->acmeProject->id, 'ideas')?->params['seeds'], 'Run anyway keeps every seed.');
        $events = array_values(array_filter($this->s->audit()->events, static fn($e): bool => $e->action === 'research.requested'));
        self::assertSame(2, $events[0]->metadata['dropped_seeds']);
        self::assertTrue($events[1]->metadata['confirmed']);
    }

    public function testOtherMarketsNeedConfirmation(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($this->url() . '?location=2840&language=en')->getBody();
        self::assertStringContainsString('is not one of the markets in your', $page);
        self::assertStringContainsString('name="confirm" value="1" required', $page);

        $refused = $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('United States · English is not one of the markets in your business profile (Germany · German)', html_entity_decode((string) $refused->getBody()));
        self::assertNull($this->research->find($this->s->acmeProject->id, 'ranked'));

        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en', 'confirm' => '1'])->getStatusCode());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'competitors', 'location' => '2276', 'language' => 'de'])->getStatusCode(), 'A profile market needs no confirmation.');
    }

    public function testResultsShowVerdictsHideNotRelevantAndTakeOverrides(): void
    {
        $project = $this->s->acmeProject;
        $this->research->queue($project->id, $project->workspaceId, 'ideas', '', ['location' => 2276, 'language' => 'de', 'seeds' => ['shoes']], $this->s->owner->id);
        $this->research->complete($project->id, 'ideas', '', [
            (new ResearchKeyword('running shoes sale', 900, 1.0, 20, 'transactional'))->toArray(),
            (new ResearchKeyword('free shoe stencils', 500, 0.1, 5, 'informational'))->toArray(),
            (new ResearchKeyword('marathon tips', 400, 0.2, 10, 'informational'))->toArray(),
        ]);
        $owner = $this->s->loggedIn($this->s->owner);
        $page = html_entity_decode((string) $owner->get($this->url())->getBody());
        self::assertStringContainsString('running shoes sale', $page);
        self::assertStringNotContainsString('free shoe stencils', $page, 'Not relevant keywords are hidden by default.');
        self::assertStringContainsString('1 not relevant to your business profile hidden.', $page);
        self::assertStringContainsString('Unsure', $page);
        self::assertStringContainsString('free shoe stencils', html_entity_decode((string) $owner->get($this->url() . '?i_rel=all')->getBody()));

        self::assertSame(303, $owner->post($this->url() . '/relevance', ['kw_relevance' => 'relevant|ideas|free shoe stencils'])->getStatusCode());
        self::assertSame(2, $this->s->container->make(BusinessContextRepository::class)->ratings($project->id)['free shoe stencils']);
        self::assertStringContainsString('free shoe stencils', html_entity_decode((string) $owner->get($this->url())->getBody()), 'The override shows it again.');
        self::assertSame(303, $owner->post($this->url() . '/relevance', ['kw_relevance' => 'not_relevant|ideas|marathon tips'])->getStatusCode());
        self::assertSame(0, $this->s->container->make(BusinessContextRepository::class)->ratings($project->id)['marathon tips']);
        self::assertSame(303, $owner->post($this->url() . '/relevance', ['kw_relevance' => 'auto|ideas|marathon tips'])->getStatusCode());
        self::assertArrayNotHasKey('marathon tips', $this->s->container->make(BusinessContextRepository::class)->ratings($project->id));

        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewer->get($this->url());
        self::assertSame(403, $viewer->post($this->url() . '/relevance', ['kw_relevance' => 'relevant|ideas|marathon tips'])->getStatusCode());
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/projects');
        self::assertSame(404, $rival->post($this->url() . '/relevance', ['kw_relevance' => 'relevant|ideas|marathon tips'])->getStatusCode());
    }

    public function testProfileFormSavesMarkets(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $base = '/projects/' . $this->s->acmeProject->publicId . '/opportunities/business';
        self::assertStringContainsString('Markets you sell in', (string) $owner->get($base)->getBody());
        $owner->post($base, ['model' => 'ecommerce', 'goal' => 'sales', 'offerings' => 'running shoes', 'market_location' => ['2040', '2276'], 'market_language' => ['de', 'de']]);
        self::assertSame(['2040:de', '2276:de'], $this->s->container->make(BusinessContextRepository::class)->find($this->s->acmeProject->id)?->markets);
    }

    private function profile(): void
    {
        $this->s->container->make(\SeoControlCenter\Modules\Opportunities\BusinessProfileService::class)->save($this->s->owner, $this->s->acmeProject, [
            'model' => 'ecommerce', 'goal' => 'sales', 'offerings' => "running shoes\ntrail shoes", 'exclusions' => "free\ndiy", 'market_location' => ['2276'], 'market_language' => ['de'],
        ], ClientInfo::cli());
        self::assertInstanceOf(BusinessContext::class, $this->s->container->make(BusinessContextRepository::class)->find($this->s->acmeProject->id));
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/keywords/research';
    }
}

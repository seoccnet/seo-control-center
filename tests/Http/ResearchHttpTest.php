<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Research\ResearchDomain;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Tests\Support\Fakes\FakeResearchProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Keyword research: queued lookups, stored results, tracking keywords,
 * gaps per competitor, failures, permissions and isolation.
 */
final class ResearchHttpTest extends TestCase
{
    private Scenario $s;

    private FakeResearchProvider $provider;

    private InMemoryResearch $research;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $provider = $this->s->container->get(FakeResearchProvider::class);
        $research = $this->s->container->get(InMemoryResearch::class);
        self::assertInstanceOf(FakeResearchProvider::class, $provider);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        $this->provider = $provider;
        $this->research = $research;
    }

    public function testRankingKeywordsAreFetchedStoredAndTracked(): void
    {
        $this->provider->ranked = [
            new ResearchKeyword('red shoes', 9900, 1.2, 45, 'commercial', 3, 'https://shop.acme.test/red', 800.0),
            new ResearchKeyword('blue shoes', 1300, 0.9, 30, 'transactional', 8, 'https://shop.acme.test/blue', 40.0),
        ];
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        $keywords->add($this->s->acmeProject->id, ['blue shoes'], KeywordLocale::default(), $this->s->owner->id);

        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Fetch ranking keywords', $page);
        self::assertStringContainsString('Not fetched yet.', $page);

        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2276', 'language' => 'de'])->getStatusCode());
        self::assertSame([['projectId' => $this->s->acmeProject->id, 'workspaceId' => $this->s->acme->id, 'kind' => 'ranked', 'subject' => '']], $this->research->queued);
        $queued = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('data-refresh-seconds', $queued);
        self::assertSame(409, $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2276', 'language' => 'de'])->getStatusCode(), 'one lookup at a time');

        $this->service()->run($this->s->acmeProject->id, $this->s->acme->id, 'ranked', '', false);
        self::assertSame(['shop.acme.test', '2276:de', ResearchService::LIMITS['ranked']], $this->provider->calls[0]['args']);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('red shoes', $page);
        self::assertStringContainsString('9,900', $page);
        self::assertStringContainsString('Germany · German', $page);
        self::assertStringContainsString('title="Already tracked"', $page, 'blue shoes is tracked');
        self::assertStringContainsString('value="red shoes"', $page);

        $sorted = (string) $owner->get($this->url() . '?r_sort=volume&r_dir=asc')->getBody();
        self::assertLessThan(strpos($sorted, 'value="red shoes"'), strpos($sorted, 'title="Already tracked"'), 'blue shoes (1,300) comes before red shoes (9,900)');
        self::assertStringContainsString('aria-sort="ascending"', $sorted);
        self::assertStringContainsString('r_sort=volume&amp;r_dir=desc', $sorted, 'the header link reverses the order');
        $filtered = (string) $owner->get($this->url() . '?r_vmin=5000&r_q=shoes')->getBody();
        self::assertStringContainsString('1 of 2 keywords match the filter.', $filtered);
        self::assertStringNotContainsString('title="Already tracked"', $filtered);
        self::assertStringContainsString('name="r_vmin" value="5000"', $filtered);
        self::assertStringContainsString('No keyword matches the filter.', (string) $owner->get($this->url() . '?r_q=boots')->getBody());

        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/track', ['keywords' => ['red shoes'], 'location' => '2276', 'language' => 'de', 'section' => 'ranked'])->getStatusCode());
        self::assertContains('red shoes', $keywords->texts($this->s->acmeProject->id));
        self::assertStringContainsString('1 keyword added', (string) $owner->get($this->url())->getBody());
        self::assertContains('research.requested', $this->s->audit()->actions());
    }

    public function testGapIdeasCompetitorsAndOverview(): void
    {
        $competitors = $this->s->container->get(InMemoryCompetitors::class);
        self::assertInstanceOf(InMemoryCompetitors::class, $competitors);
        $rival = $competitors->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        self::assertNotNull($rival);
        $this->provider->gaps['rival.test'] = [new ResearchKeyword('green shoes', 2400, null, 20, null, 4, 'https://rival.test/green')];
        $this->provider->ideas = [new ResearchKeyword('running shoes', 50000, 2.1, 70, 'commercial')];
        $this->provider->competitors = [new ResearchDomain('other.test', 5000, 100, 400, 9000.0, 120, 12.5), new ResearchDomain('rival.test', 800, null, null, null, 30, 20.0)];
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $project = $this->s->acmeProject->id;

        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'gap', 'competitor' => $rival->publicId, 'location' => '2840', 'language' => 'en'])->getStatusCode());
        $this->service()->run($project, $this->s->acme->id, 'gap', $rival->publicId, false);
        self::assertSame(['shop.acme.test', 'rival.test', '2840:en'], $this->provider->calls[0]['args']);
        $gap = (string) $owner->get($this->url() . '?competitor=' . $rival->publicId)->getBody();
        self::assertStringContainsString('green shoes', $gap);
        self::assertStringContainsString('rival.test (1)', $gap);

        $owner->get($this->url());
        $invalid = $owner->post($this->url() . '/fetch', ['kind' => 'ideas', 'seeds' => '', 'location' => '2840', 'language' => 'en']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Enter between 1 and 20 seed keywords', (string) $invalid->getBody());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'ideas', 'seeds' => "Shoes\nsneakers, shoes", 'location' => '2840', 'language' => 'en'])->getStatusCode());
        $this->service()->run($project, $this->s->acme->id, 'ideas', '', false);
        self::assertSame([['shoes', 'sneakers'], '2840:en'], $this->provider->calls[1]['args']);
        self::assertStringContainsString('running shoes', (string) $owner->get($this->url())->getBody());

        $owner->get($this->url());
        $owner->post($this->url() . '/fetch', ['kind' => 'competitors', 'location' => '2840', 'language' => 'en']);
        $this->service()->run($project, $this->s->acme->id, 'competitors', '', false);
        $owner->get($this->url());
        $owner->post($this->url() . '/fetch', ['kind' => 'overview', 'location' => '2840', 'language' => 'en']);
        $this->service()->run($project, $this->s->acme->id, 'overview', '', false);
        self::assertSame([['shop.acme.test']], $this->provider->calls[3]['args']);
        self::assertSame([['rival.test']], $this->provider->calls[4]['args']);

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('other.test', $page);
        self::assertStringContainsString('value="other.test"', $page, 'can be added as a competitor');
        self::assertStringNotContainsString('value="rival.test"', $page, 'already a competitor');
        self::assertStringContainsString('1,500', $page, 'overview traffic');
    }

    public function testFailuresAreRetriedThenShown(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en']);
        $project = $this->s->acmeProject->id;

        $this->provider->fail = new IntegrationException('DataForSEO is busy.', true);
        try {
            $this->service()->run($project, $this->s->acme->id, 'ranked', '', false);
            self::fail('A temporary error should be retried.');
        } catch (IntegrationException) {
        }
        self::assertTrue($this->research->find($project, 'ranked')?->isQueued());

        $this->provider->fail = new IntegrationException('DataForSEO is busy.', true);
        $this->service()->run($project, $this->s->acme->id, 'ranked', '', true);
        $failed = $this->research->find($project, 'ranked');
        self::assertSame('failed', $failed->status);
        self::assertStringContainsString('The lookup failed: DataForSEO is busy.', (string) $owner->get($this->url())->getBody());
    }

    public function testPermissionsAndIsolation(): void
    {
        $viewer = $this->s->loggedIn($this->s->viewer);
        $page = (string) $viewer->get($this->url())->getBody();
        self::assertStringNotContainsString('Fetch ranking keywords', $page);
        self::assertSame(403, $viewer->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en'])->getStatusCode());
        $viewer->get($this->url());
        self::assertSame(403, $viewer->post($this->url() . '/track', ['keywords' => ['x'], 'location' => '2840', 'language' => 'en'])->getStatusCode());

        $this->provider->available = false;
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringContainsString('Connect DataForSEO', (string) $owner->get($this->url())->getBody());
        self::assertSame(409, $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en'])->getStatusCode());

        $rival = $this->s->loggedIn($this->s->rivalOwner);
        self::assertSame(404, $rival->get($this->url())->getStatusCode());
        $rival->get('/');
        self::assertSame(404, $rival->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en'])->getStatusCode());
        self::assertSame([], $this->research->queued);
    }

    public function testMarketsWithoutResearchDataAreRefusedBeforeAnythingIsQueued(): void
    {
        // 0.44.3: every Google Ads country and Google language can be chosen, but DataForSEO Labs covers some pairs only.
        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('<option value="2158">Taiwan</option>', $page);
        self::assertStringContainsString('<option value="zh-TW">Chinese (Traditional)</option>', $page);
        self::assertStringContainsString('data-market-country="market-location"', $page);

        $response = $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2191', 'language' => 'en']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('DataForSEO keyword research has no data for Croatia in English. Choose Croatian.', (string) $response->getBody());
        self::assertSame([], $this->research->queued, 'nothing queued, nothing paid');

        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2158', 'language' => 'zh-TW'])->getStatusCode(), 'Taiwan in Traditional Chinese is covered');
        self::assertCount(1, $this->research->queued);
    }

    public function testMismatchedWorkspaceAndDeletedProjectsDoNotSpend(): void
    {
        $project = $this->s->acmeProject;
        $this->research->queue($project->id, $project->workspaceId, 'ranked', '', ['domain' => $project->primaryDomain], null);
        $this->service()->run($project->id, $this->s->rival->id, 'ranked', '', false);
        self::assertSame([], $this->provider->calls);
        self::assertTrue($this->research->find($project->id, 'ranked')?->isQueued());
        $this->s->container->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class)->softDelete($project);
        $this->service()->run($project->id, $project->workspaceId, 'ranked', '', false);
        self::assertSame([], $this->provider->calls);
    }

    public function testChangedProjectDomainCancelsStaleResearchBeforeSpending(): void
    {
        $project = $this->s->acmeProject;
        $sample = [(new ResearchKeyword('saved keyword', 100))->toArray()];
        $this->research->queue($project->id, $project->workspaceId, 'ranked', '', ['domain' => $project->primaryDomain], null);
        $this->research->complete($project->id, 'ranked', '', $sample);
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en'])->getStatusCode());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'ideas', 'seeds' => 'shoes', 'location' => '2840', 'language' => 'en'])->getStatusCode());
        $owner->get('/projects/' . $project->publicId . '/edit');
        self::assertSame(303, $owner->post('/projects/' . $project->publicId, ['name' => 'Changed site', 'primary_domain' => 'new-site.test', 'timezone' => 'UTC'])->getStatusCode());

        $this->service()->run($project->id, $project->workspaceId, 'ranked', '', false);
        self::assertSame([], $this->provider->calls, 'Stale domain research must not make a paid request.');
        $result = $this->research->find($project->id, 'ranked');
        self::assertNotNull($result);
        self::assertSame('failed', $result->status);
        self::assertSame($sample, $result->items);
        $this->service()->run($project->id, $project->workspaceId, 'ideas', '', false);
        self::assertSame('ideas', $this->provider->calls[0]['call'], 'Seed-based ideas remain valid after a domain change.');

        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en'])->getStatusCode());
        $this->service()->run($project->id, $project->workspaceId, 'ranked', '', false);
        self::assertSame('new-site.test', $this->provider->calls[1]['args'][0]);
    }

    public function testRemovedCompetitorGapDoesNotSpendOrFollowAReplacement(): void
    {
        $project = $this->s->acmeProject;
        $competitors = $this->s->container->make(InMemoryCompetitors::class);
        $rival = $competitors->add($project->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        self::assertNotNull($rival);
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'gap', 'competitor' => $rival->publicId, 'location' => '2840', 'language' => 'en'])->getStatusCode());
        self::assertTrue($competitors->remove($project->id, $rival->publicId));
        $replacement = $competitors->add($project->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        self::assertNotNull($replacement);

        $this->service()->run($project->id, $project->workspaceId, 'gap', $rival->publicId, false);
        self::assertSame([], $this->provider->calls, 'A removed competitor must not trigger a paid gap lookup.');
        self::assertSame('failed', $this->research->find($project->id, 'gap', $rival->publicId)?->status);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'gap', 'competitor' => $replacement->publicId, 'location' => '2840', 'language' => 'en'])->getStatusCode());
        $this->service()->run($project->id, $project->workspaceId, 'gap', $replacement->publicId, false);
        self::assertCount(1, $this->provider->calls);
        self::assertSame(['shop.acme.test', 'rival.test', '2840:en'], $this->provider->calls[0]['args']);
    }

    public function testCompletedEmptyPlatformIsNotPurchasedAgainAfterALaterFailure(): void
    {
        $provider = $this->createMock(\SeoControlCenter\Domain\Research\AiMentionsProvider::class);
        $calls = [];
        $provider->expects(self::exactly(3))->method('answers')->willReturnCallback(static function (int $workspaceId, ?int $projectId, string $domain, string $platform) use (&$calls): array {
            $calls[] = $platform;
            if ($platform === \SeoControlCenter\Domain\Research\AiMentionsProvider::CHATGPT) {
                return [];
            }
            if (count($calls) === 2) {
                throw new IntegrationException('Temporary Google failure.', true);
            }
            return [];
        });
        $this->s->container->instance(\SeoControlCenter\Domain\Research\AiMentionsProvider::class, $provider);
        $project = $this->s->acmeProject;
        $this->research->queue($project->id, $project->workspaceId, 'llm_prompts', '', ['domain' => $project->primaryDomain], null);
        try {
            $this->service()->run($project->id, $project->workspaceId, 'llm_prompts', '', false);
            self::fail('A safe transient failure should retry the unfinished platform.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
        }
        $result = $this->research->find($project->id, 'llm_prompts');
        self::assertNotNull($result);
        self::assertSame('queued', $result->status);
        $this->service()->run($project->id, $project->workspaceId, 'llm_prompts', '', false);
        self::assertSame(['chat_gpt', 'google', 'google'], $calls);
        self::assertSame('done', $this->research->find($project->id, 'llm_prompts')?->status);
    }

    private function service(): ResearchService
    {
        return $this->s->container->get(ResearchService::class);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/keywords/research';
    }
}

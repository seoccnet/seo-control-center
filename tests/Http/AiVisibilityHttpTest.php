<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\AiOverview;
use SeoControlCenter\Domain\Rankings\AiOverviewReference;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;
use SeoControlCenter\Domain\Research\AiAnswer;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Tests\Support\Fakes\FakeAiMentionsProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAiOverviews;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * AI visibility: AI Overviews from rank-check SERPs, the citations of the
 * project and its competitors, and access rules.
 */
final class AiVisibilityHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testEmptyStateBeforeTheFirstCheck(): void
    {
        $page = (string) $this->s->loggedIn($this->s->owner)->get($this->url())->getBody();

        self::assertStringContainsString('Google AI Overviews', $page);
        self::assertStringContainsString('It appears after the next check', $page);
    }

    public function testShowsAiOverviewsAndCitations(): void
    {
        $this->checkDay('2026-09-26', citeUs: false);
        $this->checkDay('2026-10-03', citeUs: true);

        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Latest check 2026-10-03', $page);
        self::assertStringContainsString('67 %', $page, '2 of 3 keywords show an AI Overview');
        self::assertStringContainsString('50 %', $page, 'cited in 1 of 2');
        self::assertStringContainsString('▲ +50 pts', $page);
        self::assertStringContainsString('Source 2', $page);
        self::assertStringContainsString('Not cited', $page);
        self::assertStringContainsString('rival.test', $page);
        self::assertStringContainsString('<span class="badge badge-warning">Competitor</span>', $page);
        self::assertStringContainsString('<span class="badge badge-ok">You</span>', $page);
        self::assertStringNotContainsString('green shoes', $page, 'keywords without an AI Overview are hidden by default');
        self::assertStringContainsString('green shoes', (string) $owner->get($this->url() . '?show=all')->getBody());
    }

    public function testViewersCanReadAndOtherWorkspacesGetNotFound(): void
    {
        $this->checkDay('2026-10-03', citeUs: true);

        self::assertSame(200, $this->s->loggedIn($this->s->viewer)->get($this->url())->getStatusCode());
        self::assertSame(404, $this->s->loggedIn($this->s->unassigned)->get($this->url())->getStatusCode());
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        self::assertSame(404, $rival->get($this->url())->getStatusCode());
    }

    public function testAiAssistantMentionsAndQuestions(): void
    {
        $competitors = $this->s->container->get(InMemoryCompetitors::class);
        self::assertInstanceOf(InMemoryCompetitors::class, $competitors);
        $competitors->add($this->s->acmeProject->id, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        $provider = $this->s->container->get(FakeAiMentionsProvider::class);
        self::assertInstanceOf(FakeAiMentionsProvider::class, $provider);
        $provider->mentions = ['chat_gpt' => ['shop.acme.test' => 12, 'rival.test' => 40], 'google' => ['shop.acme.test' => 3]];
        $provider->answers = ['chat_gpt' => [new AiAnswer('chat_gpt', 'best red shoes', 'Acme has great red shoes.', 900, 2, 'https://shop.acme.test/red', ['rival.test', 'shop.acme.test', 'guide.test'], '2026-09-30')]];
        $research = $this->s->container->get(ResearchService::class);
        $project = $this->s->acmeProject->id;

        $owner = $this->s->loggedIn($this->s->owner);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('AI assistants', $page);
        self::assertStringContainsString('Not checked yet.', $page);
        self::assertStringContainsString('about $0.40', $page, '2 domains × 2 platforms');

        $response = $owner->post('/projects/' . $this->s->acmeProject->publicId . '/keywords/research/fetch', ['kind' => 'llm', 'location' => '2840', 'language' => 'en']);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringEndsWith('/ai-visibility#assistants', $response->getHeaderLine('Location'));
        self::assertStringContainsString('data-refresh-seconds', (string) $owner->get($this->url())->getBody());
        $research->run($project, $this->s->acme->id, 'llm', '', false);
        self::assertSame([['shop.acme.test'], 'chat_gpt', '2840:en'], $provider->calls[0]['args']);
        self::assertSame([['rival.test'], 'chat_gpt', '2840:en'], $provider->calls[1]['args']);

        $owner->get($this->url());
        $owner->post('/projects/' . $this->s->acmeProject->publicId . '/keywords/research/fetch', ['kind' => 'llm_prompts', 'location' => '2840', 'language' => 'en']);
        $research->run($project, $this->s->acme->id, 'llm_prompts', '', false);
        self::assertSame(['shop.acme.test', 'chat_gpt', ResearchService::ANSWERS_PER_PLATFORM], $provider->calls[4]['args']);

        $page = (string) $owner->get($this->url())->getBody();
        self::assertLessThan(strpos($page, 'shop.acme.test <span class="badge badge-ok">You</span>'), strpos($page, '<td>rival.test</td>'), 'most mentioned first');
        self::assertStringContainsString('<td class="num">40</td>', $page);
        self::assertStringContainsString('best red shoes', $page);
        self::assertStringContainsString('>#2</span>', $page);
        self::assertStringContainsString('rival.test, guide.test', $page);

        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewerPage = (string) $viewer->get($this->url())->getBody();
        self::assertStringNotContainsString('Check mentions</button>', $viewerPage);
        self::assertStringContainsString('best red shoes', $viewerPage, 'stored results are readable');
    }

    public function testDisablingWeeklyChecksCancelsScheduledWorkButAllowsManualChecks(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/weekly', ['weekly' => '1'])->getStatusCode());
        $research = $this->s->container->make(ResearchService::class);
        self::assertSame(1, $research->startDueMentions());
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/weekly', ['weekly' => '0'])->getStatusCode());
        $project = $this->s->acmeProject;
        $research->run($project->id, $project->workspaceId, 'llm', '', false);
        $provider = $this->s->container->make(FakeAiMentionsProvider::class);
        self::assertSame([], $provider->calls, 'Disabling recurring checks must stop queued scheduled spending.');
        self::assertSame('failed', $this->s->container->make(InMemoryResearch::class)->find($project->id, 'llm')?->status);
        self::assertSame(0, $research->startDueMentions());

        $owner->get($this->url());
        self::assertSame(303, $owner->post('/projects/' . $project->publicId . '/keywords/research/fetch', ['kind' => 'llm', 'location' => '2840', 'language' => 'en'])->getStatusCode());
        $research->run($project->id, $project->workspaceId, 'llm', '', false);
        self::assertCount(2, $provider->calls, 'A manual request remains valid while weekly checks are disabled.');
    }

    public function testWeeklyMentionChecksBuildAHistory(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-09-26 06:00:00', new \DateTimeZone('UTC')));
        $this->s->container->instance(Clock::class, $clock);
        $provider = $this->s->container->get(FakeAiMentionsProvider::class);
        self::assertInstanceOf(FakeAiMentionsProvider::class, $provider);
        $provider->mentions = ['chat_gpt' => ['shop.acme.test' => 10], 'google' => ['shop.acme.test' => 4]];
        $research = $this->s->container->get(ResearchService::class);
        $research->startDueMentions();
        $project = $this->s->acmeProject->id;

        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewer->get($this->url());
        self::assertSame(403, $viewer->post($this->url() . '/weekly', ['weekly' => '1'])->getStatusCode());

        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringContainsString('Check every week</button>', (string) $owner->get($this->url())->getBody());
        self::assertSame(303, $owner->post($this->url() . '/weekly', ['weekly' => '1'])->getStatusCode());
        self::assertStringContainsString('Stop weekly check</button>', (string) $owner->get($this->url())->getBody());
        self::assertContains('research.weekly_mentions_saved', $this->s->audit()->actions());

        self::assertSame(1, $research->startDueMentions(), 'never checked: due');
        self::assertSame(0, $research->startDueMentions(), 'already queued');
        $research->run($project, $this->s->acme->id, 'llm', '', false);
        self::assertSame(0, $research->startDueMentions(), 'checked today');

        $clock->advance('+7 days');
        $provider->mentions = ['chat_gpt' => ['shop.acme.test' => 15], 'google' => ['shop.acme.test' => 6]];
        self::assertSame(1, $research->startDueMentions());
        $research->run($project, $this->s->acme->id, 'llm', '', false);

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Your mentions over time', $page);
        self::assertStringContainsString('<td>2026-10-03</td>', $page);
        self::assertStringContainsString('<td>2026-09-26</td>', $page);
        self::assertStringContainsString('<span class="delta delta-good">+7</span>', $page, '21 against 14');
    }

    private function checkDay(string $date, bool $citeUs): void
    {
        $project = $this->s->acmeProject->id;
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        if (($keywords->keywords[$project] ?? []) === []) {
            $keywords->add($project, ['red shoes', 'blue shoes', 'green shoes'], KeywordLocale::default(), $this->s->owner->id);
            $competitors = $this->s->container->get(InMemoryCompetitors::class);
            self::assertInstanceOf(InMemoryCompetitors::class, $competitors);
            $competitors->add($project, 'rival.test', Competitor::MANUAL, $this->s->owner->id);
        }
        $rankings = $this->s->container->get(InMemoryRankings::class);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        $observer = $this->s->container->get(SerpObserver::class);
        self::assertInstanceOf(SerpObserver::class, $observer);
        [$red, $blue, $green] = $keywords->keywords[$project];

        $sources = [new AiOverviewReference('https://guide.test/', 'guide.test', null)];
        if ($citeUs) {
            $sources[] = new AiOverviewReference('https://shop.acme.test/red', 'shop.acme.test', 'Red shoes');
        }
        $overviews = [
            $red['id'] => new AiOverview($sources),
            $blue['id'] => new AiOverview([new AiOverviewReference('https://www.rival.test/blue', 'www.rival.test', null)]),
            $green['id'] => null,
        ];
        foreach ([$red, $blue, $green] as $keyword) {
            $rankings->storeSnapshot(new RankSnapshot($keyword['id'], $project, SerpDevice::Desktop, $date, 4, 5, null, [], []));
            $observer->observe($project, $keyword['id'], $date, new SerpSnapshot($keyword['keyword'], KeywordLocale::default(), SerpDevice::Desktop, $date . ' 06:00:00', null, [], [], $overviews[$keyword['id']]));
        }
        $stored = $this->s->container->get(InMemoryAiOverviews::class);
        self::assertInstanceOf(InMemoryAiOverviews::class, $stored);
        self::assertCount(3, array_filter($stored->snapshots, static fn(array $s): bool => $s['date'] === $date));
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/ai-visibility';
    }
}

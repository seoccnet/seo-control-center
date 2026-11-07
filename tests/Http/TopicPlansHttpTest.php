<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaJobs;
use SeoControlCenter\Modules\ArticleIdeas\TopicPlanService;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Tests\Support\Fakes\FakeBacklinksProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleIdeas;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLinkGraph;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Fakes\InMemoryTopicPlans;
use SeoControlCenter\Tests\Support\Scenario;

/** Research → Topic plans (ADR-041, G2). */
final class TopicPlansHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testAPillarPlanProposesMembersBuildsLinksAndChecksBacklinks(): void
    {
        $this->seedIdeas();
        $this->seedAudit();
        $owner = $this->s->loggedIn($this->s->owner);
        $ideasPage = (string) $owner->get($this->base() . '/article-ideas')->getBody();
        self::assertStringContainsString('Plan a topic', $ideasPage);

        $response = $owner->post($this->base() . '/article-ideas/' . self::key('brandywine cultivar') . '/plan');
        self::assertSame(303, $response->getStatusCode());
        $plan = array_values($this->plans()->plans)[0];
        self::assertStringEndsWith('/topic-plans/' . $plan->publicId, $response->getHeaderLine('Location'));
        self::assertSame(['brandywine vs roma', 'blue brandywine 41'], array_column($plan->kept(TopicPlan::IDEA), 'label'), 'Ideas on the subject ("brandywine"), not the generic "cultivar" ones.');
        self::assertSame([$plan->id], $this->ideas()->planLinkJobs);
        self::assertContains('topic_plans.created', $this->s->audit()->actions());
        $owner->get($this->base() . '/article-ideas');
        $owner->post($this->base() . '/article-ideas/' . self::key('brandywine cultivar') . '/plan');
        self::assertCount(1, $this->plans()->plans, 'One plan per pillar.');
        self::assertStringContainsString('Open topic plan', (string) $owner->get($this->base() . '/article-ideas')->getBody());

        $this->job(ArticleIdeaJobs::PLAN_LINKS, $plan->id);
        $html = (string) $owner->get($this->planUrl($plan))->getBody();
        self::assertStringContainsString('Brandywine cultivar', $html);
        self::assertStringContainsString('<td>brandywine cultivar</td><td>brandywine vs roma</td><td>brandywine vs roma</td>', $html, 'Pillar to supporting.');
        self::assertStringContainsString('<td>brandywine vs roma</td><td>brandywine cultivar</td><td>brandywine cultivar</td>', $html, 'Supporting back to the pillar.');
        self::assertStringContainsString('/blog/cultivars</a></td><td>brandywine vs roma</td><td><strong>Brandywine vs Roma</strong>', $html, 'A paragraph on your page that names a planned article.');
        self::assertStringContainsString('Check backlinks', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));

        // Drop a supporting article: the link map follows.
        $owner->post($this->planUrl($plan) . '/members', ['keep' => [self::key('blue brandywine 41')]]);
        $plan = $this->plans()->plans[$plan->id];
        self::assertSame(['blue brandywine 41'], array_column($plan->kept(TopicPlan::IDEA), 'label'));
        $this->job(ArticleIdeaJobs::PLAN_LINKS, $plan->id);
        self::assertStringNotContainsString('<td>brandywine cultivar</td><td>brandywine vs roma</td>', (string) $owner->get($this->planUrl($plan))->getBody());

        // Paid backlink check: confirmed, queued, bought once.
        $owner->post($this->planUrl($plan) . '/backlinks');
        self::assertStringContainsString('Confirm the paid check', (string) $owner->get($this->planUrl($plan))->getBody());
        self::assertSame([], $this->ideas()->planBacklinkJobs);
        $owner->post($this->planUrl($plan) . '/backlinks', ['confirm_paid' => '1']);
        self::assertSame([$plan->id], $this->ideas()->planBacklinkJobs);
        self::assertContains('topic_plans.backlinks_requested', $this->s->audit()->actions());
        $this->backlinks()->profiles = [
            'https://rival.test/blog/brandywine' => new BacklinkProfile('https://rival.test/blog/brandywine', 31, 420, 48),
            'https://other.test/guides/brandywine' => new BacklinkProfile('https://other.test/guides/brandywine', 22, 90, 17),
        ];
        $this->job(ArticleIdeaJobs::PLAN_BACKLINKS, $plan->id);
        self::assertSame([['pageProfiles', [['https://rival.test/blog/brandywine', 'https://other.test/guides/brandywine']]]], array_map(static fn(array $c): array => [$c['call'], $c['args']], $this->backlinks()->calls), 'The top 2 competitor pages by position; the third is not bought.');
        $html = (string) $owner->get($this->planUrl($plan))->getBody();
        self::assertStringContainsString('about <strong>48 referring domains</strong> to the pillar', $html);
        $owner->post($this->planUrl($plan) . '/backlinks', ['confirm_paid' => '1']);
        self::assertStringContainsString('Tick “Check again and pay”', (string) $owner->get($this->planUrl($plan))->getBody());
        self::assertCount(1, $this->ideas()->planBacklinkJobs);

        // The pillar's brief carries the plan's links.
        $brief = html_entity_decode((string) $owner->get($this->base() . '/content-briefs/new?article_idea=' . self::key('brandywine cultivar'))->getBody());
        self::assertStringContainsString('Topic plan "brandywine cultivar": link to the article on blue brandywine 41 with the anchor "blue brandywine 41".', $brief);

        $index = (string) $owner->get($this->base() . '/topic-plans')->getBody();
        self::assertStringContainsString('~48 referring domains to match', $index);
        $owner->post($this->planUrl($plan) . '/delete');
        self::assertSame([], $this->plans()->plans);
        self::assertContains('topic_plans.deleted', $this->s->audit()->actions());
    }

    public function testFailedBacklinkCheckIsShownAndViewersCannotChangePlans(): void
    {
        $this->seedIdeas();
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->base() . '/article-ideas');
        $owner->post($this->base() . '/article-ideas/' . self::key('brandywine cultivar') . '/plan');
        $plan = array_values($this->plans()->plans)[0];
        $this->job(ArticleIdeaJobs::PLAN_LINKS, $plan->id);
        self::assertStringContainsString('Needs a finished site audit with link text', (string) $owner->get($this->planUrl($plan))->getBody());
        $owner->post($this->planUrl($plan) . '/backlinks', ['confirm_paid' => '1']);
        $this->backlinks()->fail = new IntegrationException('DataForSEO budget reached.', false);
        $this->job(ArticleIdeaJobs::PLAN_BACKLINKS, $plan->id);
        self::assertStringContainsString('The check failed: DataForSEO budget reached.', (string) $owner->get($this->planUrl($plan))->getBody());

        $viewer = $this->s->loggedIn($this->s->viewer);
        $html = (string) $viewer->get($this->planUrl($plan))->getBody();
        self::assertStringNotContainsString('Save plan', $html);
        self::assertStringNotContainsString('Delete plan', $html);
        foreach (['/members', '/links', '/backlinks', '/delete'] as $action) {
            $viewer->get($this->planUrl($plan));
            self::assertSame(403, $viewer->post($this->planUrl($plan) . $action, ['confirm_paid' => '1'])->getStatusCode(), $action);
        }
        $viewer->get($this->base() . '/article-ideas');
        self::assertSame(403, $viewer->post($this->base() . '/article-ideas/' . self::key('blue brandywine 41') . '/plan')->getStatusCode());
        self::assertCount(1, $this->plans()->plans);
    }

    private function seedIdeas(): void
    {
        $ideas = [self::idea('brandywine cultivar', ['brandywine cultivar', 'brandywine pepper'], 900.0, [
            ['https://other.test/guides/brandywine', 4], ['https://rival.test/blog/brandywine', 2], ['https://third.test/brandywine', 7],
        ])];
        $ideas[] = self::idea('brandywine vs roma', ['brandywine vs roma'], 500.0);
        $ideas[] = self::idea('blue brandywine 41', ['blue brandywine 41'], 300.0);
        foreach (['san marzano', 'black krim', 'green zebra', 'ace 47', 'cherokee purple', 'early girl', 'celebrity', 'beefsteak'] as $cultivar) {
            $ideas[] = self::idea($cultivar . ' cultivar', [$cultivar . ' cultivar'], 100.0);
        }
        $this->ideas()->replaceIdeas($this->s->acmeProject->id, $this->s->acme->id, 1, $ideas, '2026-10-18 10:00:00');
    }

    private function seedAudit(): void
    {
        $audits = $this->s->container->get(InMemorySiteAudit::class);
        $graph = $this->s->container->get(InMemoryLinkGraph::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        self::assertInstanceOf(InMemoryLinkGraph::class, $graph);
        $project = $this->s->acmeProject;
        $audit = $audits->create($project->id, $project->workspaceId, 'https://shop.acme.test/', 100, 'manual', null);
        $home = array_key_first($audits->pages[$audit->id]);
        self::assertIsInt($home);
        $audits->pages[$audit->id][$home] = new AuditPage($home, 'https://shop.acme.test/', 0, 'done');
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $graph->pages[$audit->id] = [$home => ['url' => 'https://shop.acme.test/', 'inbound' => 3], 911 => ['url' => 'https://shop.acme.test/blog/cultivars', 'inbound' => 2]];
        $graph->paragraphs[$audit->id] = [
            ['page_id' => 911, 'text' => 'Many growers compare Brandywine vs Roma before they pick their next indoor harvest.'],
            ['page_id' => $home, 'text' => 'Fast shipping on all seed orders across the country, every single day.'],
        ];
    }

    private function job(string $type, int $planId): void
    {
        $handler = new ArticleIdeaJobs($this->s->container->make(\SeoControlCenter\Core\Queue\Queue::class), null, null, $this->s->container->make(TopicPlanService::class));
        $handler->handle(['plan_id' => $planId, 'workspace_id' => $this->s->acme->id], new JobContext('job', $type, 1, 1, static fn(): bool => true, time() + 60));
    }

    /**
     * @param list<string> $keywords
     * @param list<array{0: string, 1: int}> $competitors
     */
    private static function idea(string $main, array $keywords, float $score, array $competitors = []): ArticleIdea
    {
        return new ArticleIdea(
            self::key($main),
            ArticleIdea::NEW,
            $main,
            1000,
            array_map(static fn(string $k): array => ['keyword' => $k, 'volume' => 500, 'difficulty' => 10], $keywords),
            array_map(static fn(array $c): array => ['domain' => (string) parse_url($c[0], PHP_URL_HOST), 'found' => false, 'url' => $c[0], 'position' => $c[1], 'traffic' => 10.0, 'keywords' => 1], $competitors),
            null,
            [
                'traffic' => ['low' => 10, 'expected' => 40, 'high' => 90],
                'difficulty' => ['level' => 'low', 'reasons' => []],
                'backlinks' => null,
                'months' => ['low' => 1, 'high' => 3, 'basis' => 'An assumption.'],
            ],
            $score,
            []
        );
    }

    private static function key(string $main): string
    {
        return hash('sha256', $main);
    }

    private function base(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId;
    }

    private function planUrl(TopicPlan $plan): string
    {
        return $this->base() . '/topic-plans/' . $plan->publicId;
    }

    private function plans(): InMemoryTopicPlans
    {
        $plans = $this->s->container->get(InMemoryTopicPlans::class);
        self::assertInstanceOf(InMemoryTopicPlans::class, $plans);

        return $plans;
    }

    private function ideas(): InMemoryArticleIdeas
    {
        $ideas = $this->s->container->get(InMemoryArticleIdeas::class);
        self::assertInstanceOf(InMemoryArticleIdeas::class, $ideas);

        return $ideas;
    }

    private function backlinks(): FakeBacklinksProvider
    {
        $backlinks = $this->s->container->get(FakeBacklinksProvider::class);
        self::assertInstanceOf(FakeBacklinksProvider::class, $backlinks);

        return $backlinks;
    }
}

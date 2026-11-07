<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleIdeas;
use SeoControlCenter\Tests\Support\Fakes\InMemoryTopicPlans;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Topic plans of another workspace or an unassigned project answer 404, and
 * can't be read, changed, checked or deleted through another project (ADR-041, G2).
 */
final class TopicPlansIsolationTest extends TestCase
{
    public function testForeignPlansAreNotFound(): void
    {
        $s = new Scenario();
        $plans = $s->container->get(InMemoryTopicPlans::class);
        $ideas = $s->container->get(InMemoryArticleIdeas::class);
        self::assertInstanceOf(InMemoryTopicPlans::class, $plans);
        self::assertInstanceOf(InMemoryArticleIdeas::class, $ideas);
        $secret = new ArticleIdea(hash('sha256', 'secret pillar'), ArticleIdea::NEW, 'secret pillar', 500, [['keyword' => 'secret pillar', 'volume' => 500, 'difficulty' => 10]], [['domain' => 'x.test', 'found' => false, 'url' => 'https://x.test/a', 'position' => 1, 'traffic' => 1.0, 'keywords' => 1]], null, [
            'traffic' => ['low' => 1, 'expected' => 2, 'high' => 3],
            'difficulty' => ['level' => 'low', 'reasons' => []],
            'backlinks' => null,
            'months' => ['low' => 1, 'high' => 3, 'basis' => 'x'],
        ], 1.0, []);
        $ideas->replaceIdeas($s->rivalProject->id, $s->rivalProject->workspaceId, 1, [$secret], '2026-10-18 10:00:00');
        $foreign = $plans->create($s->rivalProject, $secret->key, 'secret pillar', [['type' => TopicPlan::SEARCH, 'key' => 'secret search', 'label' => 'secret search', 'volume' => 9, 'kept' => true, 'url' => null]], null, '2026-10-18 10:00:00');

        $owner = $s->loggedIn($s->owner);
        $own = '/projects/' . $s->acmeProject->publicId;
        $rival = '/projects/' . $s->rivalProject->publicId;
        self::assertSame(404, $owner->get($rival . '/topic-plans')->getStatusCode());
        self::assertSame(404, $owner->get($rival . '/topic-plans/' . $foreign->publicId)->getStatusCode());
        self::assertSame(404, $owner->get($own . '/topic-plans/' . $foreign->publicId)->getStatusCode(), 'Never through the own project.');
        foreach (['/members', '/links', '/backlinks', '/delete'] as $action) {
            foreach ([$rival, $own] as $base) {
                $owner->get($own . '/topic-plans');
                self::assertSame(404, $owner->post($base . '/topic-plans/' . $foreign->publicId . $action, ['confirm_paid' => '1', 'keep' => []])->getStatusCode(), $action . ' through ' . $base);
            }
        }
        foreach ([$rival, $own] as $base) {
            $owner->get($own . '/topic-plans');
            self::assertSame(404, $owner->post($base . '/article-ideas/' . $secret->key . '/plan')->getStatusCode(), 'A plan from a foreign idea through ' . $base);
        }
        self::assertSame(404, $s->loggedIn($s->unassigned)->get($own . '/topic-plans')->getStatusCode());
        self::assertStringNotContainsString('secret pillar', (string) $owner->get($own . '/topic-plans')->getBody());
        self::assertCount(1, $plans->plans);
        self::assertSame([true], array_column($plans->plans[$foreign->id]->members, 'kept'), 'Unchanged.');
        self::assertNull($plans->plans[$foreign->id]->backlinksStatus);
        self::assertSame([], $ideas->planBacklinkJobs);
        self::assertSame([], $ideas->planLinkJobs);
    }
}

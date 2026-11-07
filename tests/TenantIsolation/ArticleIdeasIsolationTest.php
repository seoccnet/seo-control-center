<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleIdeas;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Article ideas of another workspace or an unassigned project answer 404 and
 * can't be dismissed through another project (ADR-041, G1).
 */
final class ArticleIdeasIsolationTest extends TestCase
{
    public function testForeignIdeasAreNotFound(): void
    {
        $s = new Scenario();
        $ideas = $s->container->get(InMemoryArticleIdeas::class);
        self::assertInstanceOf(InMemoryArticleIdeas::class, $ideas);
        $secret = new ArticleIdea(hash('sha256', 'secret topic'), ArticleIdea::NEW, 'secret topic', 500, [['keyword' => 'secret topic', 'volume' => 500, 'difficulty' => 10]], [], null, [
            'traffic' => ['low' => 10, 'expected' => 25, 'high' => 75],
            'difficulty' => ['level' => 'low', 'reasons' => []],
            'backlinks' => null,
            'months' => ['low' => 1, 'high' => 3, 'basis' => 'An assumption.'],
        ], 25.0, []);
        $ideas->replaceIdeas($s->rivalProject->id, $s->rivalProject->workspaceId, 1, [$secret], '2026-10-18 10:00:00');

        $owner = $s->loggedIn($s->owner);
        $rival = '/projects/' . $s->rivalProject->publicId . '/article-ideas';
        $own = '/projects/' . $s->acmeProject->publicId . '/article-ideas';
        self::assertSame(404, $owner->get($rival)->getStatusCode());
        $owner->get($own);
        self::assertSame(404, $owner->post($rival . '/' . $secret->key . '/dismiss')->getStatusCode(), 'Dismiss through the rival project');
        $owner->get($own);
        self::assertSame(404, $owner->post($own . '/' . $secret->key . '/dismiss')->getStatusCode(), 'Dismiss through the own project');
        $owner->get($own);
        self::assertSame(404, $owner->post($rival, ['confirm_paid' => '1'])->getStatusCode());
        $owner->get($own);
        self::assertSame(404, $owner->post($rival . '/rebuild')->getStatusCode());
        foreach ([$rival, $own] as $base) {
            $owner->get($own);
            self::assertSame(404, $owner->post($base . '/' . $secret->key . '/outline')->getStatusCode(), 'Outline of a foreign idea through ' . $base);
        }
        $brief = (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/content-briefs/new?article_idea=' . $secret->key)->getBody();
        self::assertStringNotContainsString('secret topic', $brief, 'A foreign idea never prefills a brief.');
        self::assertSame(404, $s->loggedIn($s->unassigned)->get($own)->getStatusCode());
        self::assertStringNotContainsString('secret topic', (string) $owner->get($own)->getBody());
        self::assertSame([], $ideas->dismissed);
        self::assertSame([], $ideas->queued);
        self::assertSame([], $ideas->rebuilds);
        self::assertSame([], $ideas->outlineJobs);
        self::assertSame([], $ideas->outlineRows);
    }
}

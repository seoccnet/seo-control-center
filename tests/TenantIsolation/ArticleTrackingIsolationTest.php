<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTracking;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleIdeas;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleTracking;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Published articles of another workspace answer 404, can't be stopped or
 * marked through another project, and never show there (ADR-041, G3).
 */
final class ArticleTrackingIsolationTest extends TestCase
{
    public function testForeignTrackingsAreNotFound(): void
    {
        $s = new Scenario();
        $ideas = $s->container->get(InMemoryArticleIdeas::class);
        $trackings = $s->container->get(InMemoryArticleTracking::class);
        self::assertInstanceOf(InMemoryArticleIdeas::class, $ideas);
        self::assertInstanceOf(InMemoryArticleTracking::class, $trackings);
        $secret = new ArticleIdea(hash('sha256', 'secret article'), ArticleIdea::NEW, 'secret article', 500, [['keyword' => 'secret article', 'volume' => 500, 'difficulty' => 10]], [], null, [
            'traffic' => ['low' => 1, 'expected' => 2, 'high' => 3],
            'difficulty' => ['level' => 'low', 'reasons' => []],
            'backlinks' => null,
            'months' => ['low' => 1, 'high' => 3, 'basis' => 'x'],
        ], 1.0, []);
        $ideas->replaceIdeas($s->rivalProject->id, $s->rivalProject->workspaceId, 1, [$secret], '2026-10-18 10:00:00');
        $foreign = $trackings->track($s->rivalProject, $secret, ['secret article'], ['traffic' => ['low' => 1, 'expected' => 2, 'high' => 3], 'months' => ['low' => 1, 'high' => 3, 'basis' => 'x']], 'https://rival.test/blog/secret', '2026-08-01', ArticleTracking::MANUAL, null, '2026-10-18 10:00:00');

        $owner = $s->loggedIn($s->owner);
        $own = '/projects/' . $s->acmeProject->publicId . '/article-ideas';
        $rival = '/projects/' . $s->rivalProject->publicId . '/article-ideas';
        foreach ([$rival, $own] as $base) {
            $owner->get($own);
            self::assertSame(404, $owner->post($base . '/published/' . $foreign->publicId . '/stop')->getStatusCode(), 'Stop through ' . $base);
            $owner->get($own);
            self::assertSame(404, $owner->post($base . '/' . $secret->key . '/published', ['url' => 'https://shop.acme.test/x', 'published_on' => '2026-08-01'])->getStatusCode(), 'Mark a foreign idea through ' . $base);
        }
        self::assertStringNotContainsString('secret article', (string) $owner->get($own)->getBody());
        self::assertSame([$foreign->id], array_keys($trackings->trackings));
        self::assertSame('https://rival.test/blog/secret', $trackings->trackings[$foreign->id]->url, 'Unchanged.');
    }
}

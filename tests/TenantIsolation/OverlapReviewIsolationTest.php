<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ContentInventory\OverlapFinder;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOverlapReviews;
use SeoControlCenter\Tests\Support\Scenario;

/** Overlap reviews (ADR-042) stay inside their project and workspace. */
final class OverlapReviewIsolationTest extends TestCase
{
    public function testForeignProjectsAreNotFoundAndTheirPagesStayHidden(): void
    {
        $s = new Scenario();
        $text = 'Mold on pepper starts in dense blossoms when humidity stays high for days. Check the inside of clusters for grey fuzz, cut affected blossoms well below the rot and lower the humidity in the grow room.';
        $reviews = $s->container->make(InMemoryOverlapReviews::class);
        $reviews->pages[$s->rivalProject->id] = [
            ['url' => 'https://rival.test/a', 'title' => 'Rival A', 'text' => $text],
            ['url' => 'https://rival.test/b', 'title' => 'Rival B', 'text' => $text . ' More.'],
        ];
        $key = OverlapFinder::key(OverlapFinder::DUPLICATE, ['https://rival.test/a', 'https://rival.test/b']);
        $member = $s->loggedIn($s->member);
        foreach ([$s->acmeOther, $s->rivalProject] as $project) {
            self::assertSame(404, $member->get('/projects/' . $project->publicId . '/inventory/overlap')->getStatusCode());
            $member->get('/account');
            self::assertSame(404, $member->post('/projects/' . $project->publicId . '/inventory/overlap/' . $key, ['decision' => 'keep_separate', 'version' => '0'])->getStatusCode());
        }
        $owner = $s->loggedIn($s->owner);
        $owner->get('/account');
        self::assertSame(404, $owner->post('/projects/' . $s->acmeProject->publicId . '/inventory/overlap/' . $key, ['decision' => 'keep_separate', 'version' => '0'])->getStatusCode(), 'Another project\'s candidate is not a candidate here.');
        self::assertStringNotContainsString('rival.test', (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/inventory/overlap')->getBody());
        self::assertSame([], $reviews->reviews($s->rivalProject->id));
    }
}

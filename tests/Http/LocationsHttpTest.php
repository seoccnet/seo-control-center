<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Modules\Local\LocationService;
use SeoControlCenter\Tests\Support\Fakes\FakeLocalProvider;
use SeoControlCenter\Tests\Support\Fakes\FakeReviewsProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLocations;
use SeoControlCenter\Tests\Support\Scenario;

final class LocationsHttpTest extends TestCase
{
    public function testExpiredReviewRequestsDoNotPurchaseOrPollTasks(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/local/locations';
        $owner->get($base);
        $owner->post($base, ['name' => 'East', 'cid' => '222', 'coordinates' => '43.8, 18.4', 'zoom' => '13', 'language' => 'en', 'keywords' => 'shop']);
        $repo = $s->container->make(InMemoryLocations::class);
        $location = $repo->locations($s->acmeProject->id)[0];
        $reviews = $s->container->make(FakeReviewsProvider::class);
        $sample = [['id' => 'old-review', 'author' => 'Customer', 'text' => 'Previous review', 'rating' => 5.0, 'published' => null, 'reply' => null]];
        $createdAt = $s->container->make(Clock::class)->now()->modify('-25 hours')->format('Y-m-d H:i:s');
        foreach (['queued' => 'reviews', 'pending' => 'poll'] as $status => $mode) {
            $repo->saveReviews($s->acmeProject->id, $location->publicId, ['status' => $status, 'cid' => '222', 'created_at' => $createdAt, 'task' => '00000000-0000-4000-8000-000000000001', 'items' => $sample]);
            $s->container->make(LocationService::class)->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $location->publicId, $mode);
            $state = $repo->reviews($s->acmeProject->id, $location->publicId);
            self::assertSame('failed', $state['status']);
            self::assertSame($sample, $state['items']);
            self::assertSame(0, $reviews->submissions, 'An expired request must not purchase a task before failing.');
            self::assertSame(0, $reviews->polls);
        }
    }

    public function testReviewRefreshRetainsOnlyTheSameBusinessSample(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/local/locations';
        $owner->get($base);
        $owner->post($base, ['name' => 'East', 'cid' => '222', 'coordinates' => '43.8, 18.4', 'zoom' => '13', 'language' => 'en', 'keywords' => 'shop']);
        $repo = $s->container->make(InMemoryLocations::class);
        $location = $repo->locations($s->acmeProject->id)[0];
        $sample = [['id' => 'old-review', 'author' => 'Customer', 'text' => 'Previous business review', 'rating' => 5.0, 'published' => '2026-10-01', 'reply' => null]];
        foreach (['111', '222'] as $cid) {
            $repo->saveReviews($s->acmeProject->id, $location->publicId, ['status' => 'done', 'cid' => $cid, 'items' => $sample]);
            $owner->get($base);
            self::assertSame(303, $owner->post($base . '/' . $location->publicId . '/reviews')->getStatusCode());
            $state = $repo->reviews($s->acmeProject->id, $location->publicId);
            self::assertSame('222', $state['cid']);
            self::assertSame($cid === '222' ? $sample : [], $state['items']);
        }
    }

    public function testBranchGridUsesExactCidAndResumesWithoutRepeatingCompletedPoints(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/local/locations';
        $owner->get($base);
        $owner->post($base, ['name' => 'East', 'cid' => '222', 'coordinates' => '43.8, 18.4', 'zoom' => '13', 'language' => 'en', 'keywords' => 'shop']);
        $repo = $s->container->make(InMemoryLocations::class);
        $location = $repo->locations($s->acmeProject->id)[0];
        $owner->get($base);
        $input = ['grid_keyword' => 'shop', 'grid_size' => '3', 'grid_spacing' => '500'];
        $path = $base . '/' . $location->publicId . '/grid';
        self::assertSame(303, $owner->post($path, $input)->getStatusCode());
        self::assertSame(409, $owner->post($path, $input)->getStatusCode());
        $provider = $s->container->make(FakeLocalProvider::class);
        $provider->maps['shop'] = [new LocalResult(1, 'West', domain: $s->acmeProject->primaryDomain, cid: '111'), new LocalResult(7, 'East', cid: '222')];
        $service = $s->container->make(LocationService::class);
        $service->run($s->acmeProject->id, $s->rival->id, $s->acmeProject->publicId, $location->publicId, 'grid');
        self::assertSame([], $provider->calls);
        for ($i = 0; $i < 10; ++$i) {
            $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $location->publicId, 'grid');
        }
        $grid = $repo->grids($s->acmeProject->id, $location->publicId)[0];
        self::assertSame('done', $grid->grid->status);
        self::assertSame(array_fill(0, 9, 7), $grid->grid->positions);
        self::assertCount(9, $provider->calls);
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $location->publicId, 'grid');
        self::assertCount(9, $provider->calls);
        self::assertStringContainsString('9/9 points checked', (string) $owner->get($base)->getBody());
        self::assertSame(303, $owner->post($path, $input)->getStatusCode());
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $location->publicId, 'grid', $grid->publicId);
        self::assertCount(9, $provider->calls, 'A stale continuation must not check points in the newly requested run.');
        $service->failGrid($s->acmeProject->id, $location->publicId, 'Old failure', $grid->publicId);
        self::assertNotNull($repo->queuedGrid($s->acmeProject->id, $location->publicId), 'A stale failure must not stop the newer run.');
        $viewer = $s->loggedIn($s->viewer);
        $viewer->get($base);
        self::assertSame(403, $viewer->post($path, $input)->getStatusCode());
        $owner->get($base);
        self::assertSame(404, $owner->post('/projects/' . $s->rivalProject->publicId . '/local/locations/' . $location->publicId . '/grid', $input)->getStatusCode());
    }

    public function testBranchesMatchTheirOwnCidAndReviewPollingNeverResubmits(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/local/locations';
        self::assertSame(200, $owner->get($base)->getStatusCode());
        $body = ['name' => 'Acme East', 'cid' => '222', 'coordinates' => '43.8, 18.4', 'zoom' => '13', 'language' => 'en', 'keywords' => 'seed shop', 'weekly' => '1'];
        self::assertSame(303, $owner->post($base, $body)->getStatusCode());
        $repo = $s->container->make(InMemoryLocations::class);
        $location = $repo->locations($s->acmeProject->id)[0];
        $provider = $s->container->make(FakeLocalProvider::class);
        $provider->maps['seed shop'] = [new LocalResult(1, 'Acme West', domain: $s->acmeProject->primaryDomain, cid: '111'), new LocalResult(7, 'Acme East', domain: $s->acmeProject->primaryDomain, cid: '222')];
        $provider->profile = new BusinessProfile('Acme East', rating: 4.5, votes: 12, cid: '222');
        $service = $s->container->make(LocationService::class);
        $service->run($s->acmeProject->id, $s->rival->id, $s->acmeProject->publicId, $location->publicId, 'check');
        self::assertSame([], $provider->calls);
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $location->publicId, 'check');
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $location->publicId, 'check');
        self::assertCount(2, $provider->calls);
        $history = $repo->history($s->acmeProject->id, $location->publicId);
        self::assertSame(7, $history[0]['result']['keywords']['seed shop']);
        self::assertSame(4.5, $history[0]['result']['profile']['rating']);
        $owner->get($base);
        self::assertSame(303, $owner->post($base . '/' . $location->publicId . '/reviews')->getStatusCode());
        $reviews = $s->container->make(FakeReviewsProvider::class);
        $reviews->pending = true;
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $location->publicId, 'reviews');
        self::assertSame('pending', $repo->reviews($s->acmeProject->id, $location->publicId)['status']);
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $location->publicId, 'reviews');
        self::assertSame(1, $reviews->submissions);
        $owner->get($base);
        self::assertSame(409, $owner->post($base . '/' . $location->publicId . '/reviews')->getStatusCode());
        $reviews->pending = false;
        $reviews->items = [['id' => 'r', 'author' => 'Customer', 'text' => '<script>alert(1)</script>', 'rating' => 5.0, 'published' => '2026-10-01', 'reply' => 'Thank you']];
        $service->run($s->acmeProject->id, $s->acme->id, $s->acmeProject->publicId, $location->publicId, 'poll');
        $html = (string) $owner->get($base)->getBody();
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringContainsString('Owner reply:', $html);
        self::assertSame(1, $reviews->submissions);
        $owner->get($base);
        self::assertSame(303, $owner->post($base . '/' . $location->publicId . '/settings', ['name' => 'East branch', 'keywords' => 'seed shop'])->getStatusCode());
        self::assertFalse($repo->find($s->acmeProject->id, $location->publicId)?->weekly);
        self::assertCount(1, $repo->history($s->acmeProject->id, $location->publicId));
        self::assertSame(303, $owner->post($base . '/' . $location->publicId . '/delete')->getStatusCode());
        self::assertSame([], $repo->locations($s->acmeProject->id));
        self::assertSame([], $repo->reviews($s->acmeProject->id, $location->publicId));
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Modules\Local\LocalLocation;
use SeoControlCenter\Modules\Local\LocalRepository;
use SeoControlCenter\Modules\Local\LocationRepository;

final class LocationsPersistenceTest extends FullStackTestCase
{
    public function testAdditionalLocationRecordsRemainSeparateAndLeavePrimaryHistoryIntact(): void
    {
        $owner = $this->createUser('locations@acme.test', 'Locations');
        self::assertNotNull($owner['workspace']);
        $public = $this->createProject($this->browserFor('locations@acme.test'), 'Locations', 'locations.test');
        $id = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$public]);
        $primary = $this->app->make(LocalRepository::class);
        $primary->saveSettings($id, $owner['workspace']->id, 'Primary', new LocalArea(1, 1), 'en', false, $owner['user']->id);
        $repo = $this->app->make(LocationRepository::class);
        $uuid = '00000000-0000-4000-8000-000000000001';
        $repo->save(new LocalLocation($uuid, $id, 'East', '222', new LocalArea(2, 2), 'en', ['shop'], true));
        self::assertCount(1, $repo->locations($id));
        self::assertNull($repo->find($id + 100, $uuid));
        self::assertSame('222', $repo->find($id, $uuid)?->cid);
        $repo->saveRanking($id, $uuid, '2026-10-04', ['keywords' => ['shop' => null], 'profile' => ['rating' => 4.5]]);
        $repo->saveRanking($id, $uuid, '2026-10-04', ['keywords' => ['shop' => 7], 'profile' => ['rating' => 4.5]]);
        self::assertCount(1, $repo->history($id, $uuid));
        self::assertSame([], $repo->history($id + 100, $uuid));
        self::assertSame(7, $repo->ranking($id, $uuid, '2026-10-04')['keywords']['shop']);
        self::assertCount(1, $repo->due('2026-10-04'));
        $repo->markChecked($id, $uuid, '2026-10-04');
        self::assertSame([], $repo->due('2026-09-27'));
        $repo->saveReviews($id, $uuid, ['cid' => '222', 'items' => [['text' => 'Great']]]);
        self::assertSame([], $repo->reviews($id + 100, $uuid));
        $grid = new \SeoControlCenter\Modules\Local\BranchGrid('00000000-0000-4000-8000-000000000002', 'shop', new \SeoControlCenter\Domain\Local\LocalGrid(0, '2026-10-05', 3, 500, new LocalArea(2, 2), [], 'queued'));
        $repo->createGrid($id, $uuid, $grid);
        $repo->saveGridPoint($id, $grid->publicId, 0, null);
        $repo->saveGridPoint($id, $grid->publicId, 1, 7);
        self::assertSame([null, 7], $repo->queuedGrid($id, $uuid)?->grid->positions);
        self::assertSame([], $repo->grids($id + 100, $uuid));
        $repo->finishGrid($id, $grid->publicId, 'done');
        self::assertNull($repo->queuedGrid($id, $uuid));
        $repo->remove($id, $uuid);
        self::assertSame([], $repo->history($id, $uuid));
        self::assertSame([], $repo->reviews($id, $uuid));
        self::assertSame([], $repo->grids($id, $uuid));
        self::assertSame('Primary', $primary->settings($id)?->businessName);
    }
}

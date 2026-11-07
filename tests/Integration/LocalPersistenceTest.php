<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Modules\Local\LocalRepository;

/**
 * Local SEO settings, keywords, positions and profiles against MySQL/MariaDB.
 */
final class LocalPersistenceTest extends FullStackTestCase
{
    public function testSettingsKeywordsRankingsAndProfiles(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $user = $acme['user']->id;
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000061', ?, 'Shop', 'www.shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$ws],
        );
        $project = $this->db->lastInsertId();
        $repo = $this->app->make(LocalRepository::class);
        self::assertSame('www.shop.test', $repo->projectDomain($project));
        self::assertNull($repo->settings($project));

        $repo->saveSettings($project, $ws, 'Shop Springfield', new LocalArea(43.856345, 18.413121, 14), 'bs', true, $user);
        $repo->setCid($project, '777');
        $settings = $repo->settings($project);
        self::assertSame(['Shop Springfield', 43.856345, 18.413121, 14, 'bs', true, '777', 'idle'], [$settings?->businessName, $settings?->area->latitude, $settings?->area->longitude, $settings?->area->zoom, $settings?->language, $settings?->weekly, $settings?->cid, $settings?->status]);
        $repo->saveSettings($project, $ws, 'Shop Springfield', new LocalArea(43.8, 18.4, 13), 'bs', true, $user);
        self::assertSame('777', $repo->settings($project)?->cid, 'same name keeps the listing');
        $repo->saveSettings($project, $ws, 'Shop Region', new LocalArea(43.8, 18.4, 13), 'bs', true, $user);
        self::assertNull($repo->settings($project)?->cid, 'a new name forgets it');

        self::assertSame(2, $repo->addKeywords($project, ['seed shop', 'garden center', 'seed shop']));
        self::assertSame(['garden center', 'seed shop'], array_column($repo->keywords($project), 'keyword'));
        [$garden, $seed] = $repo->keywords($project);
        self::assertCount(2, $repo->unchecked($project, '2026-10-03'));

        $repo->recordRanking($project, $seed['id'], '2026-09-26', 4, [new LocalResult(1, 'Rival', 'rival.test', rating: 4.9, votes: 300)]);
        $repo->recordRanking($project, $seed['id'], '2026-10-03', 2, [new LocalResult(1, 'Rival', 'rival.test', rating: 4.9, votes: 300), new LocalResult(2, 'Shöp', 'shop.test')]);
        $repo->recordRanking($project, $garden['id'], '2026-10-03', null, []);
        self::assertSame([], $repo->unchecked($project, '2026-10-03'));
        self::assertSame('2026-10-03', $repo->latestDate($project));
        self::assertSame('2026-09-26', $repo->dateBefore($project, '2026-10-03'));
        $rankings = $repo->rankings($project, '2026-10-03');
        self::assertSame(2, $rankings[$seed['id']]['position']);
        self::assertSame('Shöp', $rankings[$seed['id']]['top'][1]->title);
        self::assertSame(4.9, $rankings[$seed['id']]['top'][0]->rating);
        self::assertNull($rankings[$garden['id']]['position']);

        $repo->recordProfile($project, '2026-09-26', new BusinessProfile('Shop', null, 4.5, 70));
        $repo->recordProfile($project, '2026-10-03', new BusinessProfile('Shop', 'Garden center', 4.6, 80, false, 'Main St 1', null, 'https://shop.test/', 12));
        $profiles = $repo->profiles($project, 10);
        self::assertSame(['2026-10-03', '2026-09-26'], array_column($profiles, 'date'));
        self::assertSame([4.6, 80, false, 12], [$profiles[0]['profile']->rating, $profiles[0]['profile']->votes, $profiles[0]['profile']->claimed, $profiles[0]['profile']->photos]);

        self::assertSame([['projectId' => $project, 'workspaceId' => $ws]], $repo->dueWeekly('2026-10-01'), 'never checked');
        $repo->markChecked($project, '2026-10-03');
        self::assertSame([], $repo->dueWeekly('2026-10-01'));
        self::assertCount(1, $repo->dueWeekly('2026-10-03'));
        $repo->setStatus($project, 'queued');
        self::assertSame([], $repo->dueWeekly('2026-10-10'), 'queued checks are not queued again');
        $repo->setStatus($project, 'failed', 'Budget reached.');
        self::assertSame('Budget reached.', $repo->settings($project)?->lastError);

        // Ranking grids: points fill in one by one; the newest grid per keyword is shown.
        $area = new LocalArea(43.8, 18.4, 13);
        $repo->createGrid($project, $seed['id'], '2026-09-26', 3, 500, $area);
        $first = $repo->queuedGrid($project);
        self::assertNotNull($first);
        self::assertSame(['seed shop', [], 'queued'], [$first['keyword'], $first['grid']->positions, $first['grid']->status]);
        $repo->saveGridPoint($first['id'], 0, 4);
        $repo->saveGridPoint($first['id'], 8, null);
        $repo->saveGridPoint($first['id'], 0, 3);
        $partial = $repo->queuedGrid($project);
        self::assertNotNull($partial);
        self::assertSame([0 => 3, 8 => null], $partial['grid']->positions, 'numbers and null are stored; a point can be redone');
        $repo->finishGrid($first['id'], 'failed', 'Budget reached.');
        self::assertNull($repo->queuedGrid($project));
        $repo->createGrid($project, $seed['id'], '2026-10-03', 5, 1000, $area);
        $repo->createGrid($project, 999999, '2026-10-03', 5, 1000, $area);
        $second = $repo->queuedGrid($project);
        self::assertNotNull($second);
        $repo->saveGridPoint($second['id'], 12, 1);
        $repo->finishGrid($second['id'], 'done');
        self::assertNull($repo->queuedGrid($project), 'a grid for a keyword of no project is not created');
        $grids = $repo->latestGrids($project);
        self::assertSame([$seed['id']], array_keys($grids));
        self::assertSame([5, 1000, [12 => 1], 'done', '2026-10-03'], [$grids[$seed['id']]->size, $grids[$seed['id']]->spacing, $grids[$seed['id']]->positions, $grids[$seed['id']]->status, $grids[$seed['id']]->checkedOn]);

        self::assertTrue($repo->removeKeyword($project, 'seed shop'));
        self::assertSame([], $repo->latestGrids($project), 'grids go with the keyword');
        self::assertFalse($repo->removeKeyword($project + 1, 'garden center'));
        self::assertSame([], $repo->rankings($project, '2026-09-26'), 'its history goes with it');
    }
}

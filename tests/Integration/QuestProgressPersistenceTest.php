<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Quests\QuestProgressRepository;
use SeoControlCenter\Modules\Quests\Jobs\RecheckQuestsJob;

final class QuestProgressPersistenceTest extends FullStackTestCase
{
    public function testProgressRanksAndPreferencesAreStoredPerUserAndWorkspace(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($owner['workspace']);
        self::assertNotNull($rival['workspace']);
        $u = $owner['user']->id;
        $w = $owner['workspace']->id;
        $repo = $this->app->make(QuestProgressRepository::class);

        $empty = $repo->profile($u, $w);
        self::assertFalse($empty->hidden);
        self::assertNull($empty->seenQuests);
        self::assertNull($empty->checkedAt);
        $repo->setHidden($u, $w, true);
        $repo->markSeen($u, $w, ['impact.did-it-work', 'wins.next-move']);
        $repo->markChecked($u, $w, '2026-10-14 09:00:00');
        $profile = $repo->profile($u, $w);
        self::assertTrue($profile->hidden);
        self::assertSame(['impact.did-it-work', 'wins.next-move'], $profile->seenQuests);
        self::assertSame('2026-10-14 09:00:00', $profile->checkedAt);
        self::assertFalse($repo->profile($rival['user']->id, $rival['workspace']->id)->hidden);
        self::assertFalse($repo->profile($u, $rival['workspace']->id)->hidden);

        // Completion is sticky and keeps the first time; it never leaks to other users or workspaces.
        $repo->complete($u, $w, 'impact.did-it-work', 'record', '2026-10-14 09:00:00');
        $repo->complete($u, $w, 'impact.did-it-work', 'record', '2026-10-15 09:00:00');
        $repo->complete($u, $w, 'impact.did-it-work', '', '2026-10-16 09:00:00');
        $progress = $repo->progress($u, $w);
        self::assertSame('2026-10-14 09:00:00', $progress['impact.did-it-work']['record']['completed']);
        self::assertSame('2026-10-16 09:00:00', $progress['impact.did-it-work']['']['completed']);
        self::assertSame([], $repo->progress($rival['user']->id, $rival['workspace']->id));
        self::assertSame([], $repo->progress($u, $rival['workspace']->id));

        $repo->skip($u, $w, 'wins.next-move', '2026-10-14 10:00:00');
        $repo->skip($u, $w, 'wins.next-move', '2026-10-15 10:00:00');
        self::assertSame('2026-10-14 10:00:00', $repo->progress($u, $w)['wins.next-move']['']['skipped']);
        $repo->unskip($u, $w, 'wins.next-move');
        self::assertNull($repo->progress($u, $w)['wins.next-move']['']['skipped']);
        // Skipping a completed quest keeps its completion.
        $repo->skip($u, $w, 'impact.did-it-work', '2026-10-17 10:00:00');
        self::assertSame('2026-10-16 09:00:00', $repo->progress($u, $w)['impact.did-it-work']['']['completed']);

        $repo->earnRank($u, $w, 1, '2026-10-14 09:00:00');
        $repo->earnRank($u, $w, 1, '2026-10-20 09:00:00');
        self::assertSame([1 => ['earned' => '2026-10-14 09:00:00', 'announced' => false]], $repo->ranks($u, $w));
        $repo->announceRanks($u, $w, [1], '2026-10-14 09:01:00');
        $repo->announceRanks($u, $w, [], '2026-10-14 09:01:00');
        self::assertTrue($repo->ranks($u, $w)[1]['announced']);
        self::assertSame([], $repo->ranks($rival['user']->id, $rival['workspace']->id));
    }

    public function testDueProfilesAreBoundedOrderedAndSkipInactiveMemberships(): void
    {
        $repo = $this->app->make(QuestProgressRepository::class);
        $rows = [];
        foreach (range(1, 4) as $i) {
            $user = $this->createUser('user' . $i . '@acme.test', 'Acme ' . $i);
            self::assertNotNull($user['workspace']);
            $rows[] = [$user['user']->id, $user['workspace']->id];
        }
        $repo->markChecked($rows[0][0], $rows[0][1], '2026-10-14 08:00:00');
        $repo->markChecked($rows[1][0], $rows[1][1], '2026-10-14 06:00:00');
        $repo->markChecked($rows[2][0], $rows[2][1], '2026-10-14 09:30:00');
        $repo->markChecked($rows[3][0], $rows[3][1], '2026-10-14 05:00:00');
        $this->db->execute("UPDATE workspace_users SET status = 'suspended' WHERE user_id = ?", [$rows[3][0]]);

        $due = $repo->due('2026-10-14 09:00:00', 10);
        self::assertSame([['user_id' => $rows[1][0], 'workspace_id' => $rows[1][1]], ['user_id' => $rows[0][0], 'workspace_id' => $rows[0][1]]], $due);
        self::assertCount(1, $repo->due('2026-10-14 09:00:00', 1));
    }

    public function testCronRecheckIsBoundedAndIdempotent(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $u = $owner['user']->id;
        $w = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES ('0192f0a0-0000-7000-8000-000000000101', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$w]);
        $project = $this->db->lastInsertId();
        $this->db->execute("INSERT INTO site_audits (public_id, project_id, workspace_id, status, trigger_type, start_url, max_pages, started_by, created_at, updated_at) VALUES (UUID(), ?, ?, 'completed', 'manual', 'https://shop.test/', 100, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$project, $w, $u]);
        $repo = $this->app->make(QuestProgressRepository::class);
        $repo->markChecked($u, $w, '2000-01-01 00:00:00');
        $extra = [];
        foreach (range(1, RecheckQuestsJob::BATCH + 5) as $i) {
            $other = $this->createUser('member' . $i . '@acme.test', 'Other ' . $i);
            self::assertNotNull($other['workspace']);
            $repo->markChecked($other['user']->id, $other['workspace']->id, '2001-01-01 00:00:00');
            $extra[] = $other;
        }

        self::assertTrue($this->app->make(JobRegistry::class)->has('quests.recheck'));
        $job = $this->app->make(JobRegistry::class)->handler('quests.recheck');
        self::assertInstanceOf(RecheckQuestsJob::class, $job);
        $job->handle([], $this->context());

        $completed = $repo->progress($u, $w)['mastery.site-audit']['']['completed'] ?? null;
        self::assertNotNull($completed);
        // Bounded: one run re-checks at most BATCH profiles, oldest first.
        $now = $this->app->make(Clock::class)->now()->setTimezone(new \DateTimeZone('UTC'))->modify('-1 hour')->format('Y-m-d H:i:s');
        self::assertCount(6, $repo->due($now, 100));
        $rows = (int) $this->db->fetchValue('SELECT COUNT(*) FROM quest_progress');

        // Idempotent: a second run adds nothing for already-checked profiles and keeps the completion time.
        $job->handle([], $this->context());
        self::assertSame($completed, $repo->progress($u, $w)['mastery.site-audit']['']['completed'] ?? null);
        self::assertSame($rows, (int) $this->db->fetchValue('SELECT COUNT(*) FROM quest_progress'));
        self::assertSame([], $repo->due($now, 100));
        // Re-checking only reads: it never queues other work.
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM jobs'));
    }

    private function context(): JobContext
    {
        return new JobContext(Uuid::v7(), 'quests.recheck', 1, 3, static fn(): bool => true, time() + 300);
    }
}

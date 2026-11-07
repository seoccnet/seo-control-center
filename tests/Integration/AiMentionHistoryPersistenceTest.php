<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Modules\Research\AiMentionHistory;

/**
 * Weekly AI mention settings and history against MySQL/MariaDB.
 */
final class AiMentionHistoryPersistenceTest extends FullStackTestCase
{
    public function testWeeklySettingAndHistory(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000071', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$ws],
        );
        $project = $this->db->lastInsertId();
        $history = $this->app->make(AiMentionHistory::class);

        self::assertFalse($history->isWeekly($project));
        self::assertSame([], $history->dueWeekly('2026-10-01'));
        $history->setWeekly($project, $ws, true);
        self::assertTrue($history->isWeekly($project));
        self::assertSame([['projectId' => $project, 'workspaceId' => $ws, 'projectPublicId' => '0192f0a0-0000-7000-8000-000000000071']], $history->dueWeekly('2026-10-01'));

        $history->record($project, $ws, '2026-09-26', [new AiMention('shop.test', 'chat_gpt', 10, 900), new AiMention('shop.test', 'google', 4, null), new AiMention('rival.test', 'chat_gpt', 30, 5000)]);
        $history->record($project, $ws, '2026-10-03', [new AiMention('shop.test', 'chat_gpt', 7, 800)]);
        $history->record($project, $ws, '2026-10-03', [new AiMention('shop.test', 'chat_gpt', 15, 1200)]);

        self::assertSame([], $history->dueWeekly('2026-10-01'), 'checked on 2026-10-03');
        self::assertCount(1, $history->dueWeekly('2026-10-03'));
        self::assertTrue($history->isWeekly($project), 'recording keeps the setting');
        $rows = $history->history($project, 8);
        self::assertSame(['2026-10-03', '2026-09-26'], array_column($rows, 'date'));
        self::assertSame(['shop.test' => ['chat_gpt' => 15]], $rows[0]['mentions'], 'a re-check of the same day replaces it');
        self::assertSame(['chat_gpt' => 10, 'google' => 4], $rows[1]['mentions']['shop.test']);
        self::assertSame(30, $rows[1]['mentions']['rival.test']['chat_gpt']);
        self::assertCount(1, $history->history($project, 1));

        $history->setWeekly($project, $ws, false);
        self::assertSame([], $history->dueWeekly('2026-12-01'));
    }
}

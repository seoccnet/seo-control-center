<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\BotLogs\BotLogImport;
use SeoControlCenter\Modules\BotLogs\BotLogRepository;

/** Bot log totals (1.2.0) on the real engine: chunk merging, row cap, reports and retention. */
final class BotLogPersistenceTest extends FullStackTestCase
{
    public function testChunksMergeAndReportsRead(): void
    {
        $owner = $this->createUser('logs@bots.test', 'Logs');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('logs@bots.test'), 'Logs', 'logs.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $repo = $this->app->make(BotLogRepository::class);

        $import = $repo->create($projectId, $owner['workspace']->id, 'access.log', 1000, $owner['user']->id);
        self::assertFalse($repo->saveChunk($import->id, [], 10, 1, 0, null, null, 10), 'Only a running import takes chunks.');
        $repo->markRunning($import->id);
        $hit = static fn(string $bot, string $path, int $status, int $hits): array => ['bot' => $bot, 'path' => $path, 'status' => $status, 'hits' => $hits];
        self::assertTrue($repo->saveChunk($import->id, [
            'a' => $hit('googlebot', '/a', 200, 5),
            'b' => $hit('googlebot', '/b?x=1', 200, 2),
            'c' => $hit('googlebot', '/gone', 404, 3),
        ], 500, 20, 10, '2026-11-02 00:00:00', '2026-11-03 00:00:00', 4));
        self::assertTrue($repo->saveChunk($import->id, [
            'a' => $hit('googlebot', '/a', 200, 1),
            'd' => $hit('bingbot', '/a', 301, 1),
            'e' => $hit('bingbot', '/new', 200, 7),
        ], 1000, 5, 3, '2026-11-01 00:00:00', null, 4));

        $saved = $repo->byId($import->id) ?? self::fail('Import not saved.');
        self::assertSame(1000, $saved->byteOffset);
        self::assertSame(25, $saved->linesRead);
        self::assertSame(13, $saved->botLines);
        self::assertSame(7, $saved->droppedHits, 'The fifth distinct row is over the cap of 4.');
        self::assertSame('2026-11-01 00:00:00', $saved->firstSeen);
        self::assertSame('2026-11-03 00:00:00', $saved->lastSeen);

        $totals = $repo->botTotals($import->id);
        self::assertSame(['hits' => 11, 'paths' => 3, 'ok' => 8, 'redirects' => 0, 'client_errors' => 3, 'server_errors' => 0, 'with_query' => 2], $totals['googlebot']);
        self::assertSame(1, $totals['bingbot']['redirects']);
        self::assertSame([['path' => '/a', 'status' => 200, 'hits' => 6]], $repo->topPaths($import->id, 'googlebot', 1));
        self::assertSame([['path' => '/gone', 'status' => 404, 'hits' => 3]], $repo->topPaths($import->id, 'googlebot', 5, 400, 599));
        self::assertSame([['path' => '/b?x=1', 'status' => 200, 'hits' => 2]], $repo->topPaths($import->id, 'googlebot', 5, null, null, true));
        self::assertSame(['/a' => 6], $repo->hitsFor($import->id, 'googlebot', ['/a', '/missing']));

        $repo->finish($import->id, BotLogImport::DONE);
        self::assertFalse($repo->saveChunk($import->id, [], 2000, 1, 0, null, null, 4));
        for ($i = 0; $i < 5; ++$i) {
            $repo->create($projectId, $owner['workspace']->id, 'newer.log', 10, $owner['user']->id);
        }
        $due = array_map(static fn(BotLogImport $i): int => $i->id, $repo->due('2000-01-01 00:00:00', 5));
        self::assertSame([$import->id], $due, 'Only the import beyond the newest five is due.');
        $repo->delete($import->id);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM bot_log_hits'));
    }
}

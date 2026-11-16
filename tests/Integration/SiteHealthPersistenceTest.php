<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\SiteHealth\SiteHealthRepository;

/** Site health state and events (1.3.0) on the real engine. */
final class SiteHealthPersistenceTest extends FullStackTestCase
{
    public function testStateEventsAndDue(): void
    {
        $owner = $this->createUser('health@site.test', 'Health');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('health@site.test'), 'Health', 'health.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $workspaceId = $owner['workspace']->id;
        $repo = $this->app->make(SiteHealthRepository::class);

        self::assertTrue($repo->state($projectId)->enabled, 'Monitoring is on without a stored row.');
        self::assertSame([['projectId' => $projectId, 'workspaceId' => $workspaceId, 'domain' => 'health.test']], $repo->due('uptime', '2026-11-16 10:00:00', 10));

        $state = $repo->state($projectId)->with(['uptimeStatus' => 'up', 'uptimeCode' => 200, 'uptimeMs' => 120, 'uptimeCheckedAt' => '2026-11-16 10:00:00', 'robotsText' => str_repeat('Disallow: /x' . "\n", 100), 'robotsHash' => sha1('x'), 'robotsStatus' => 200, 'sitemapUrls' => 42, 'sslExpiresAt' => '2027-01-01 00:00:00']);
        $repo->save($workspaceId, $state, '2026-11-16 10:00:00');
        $loaded = $repo->state($projectId);
        self::assertSame([200, 120, 42, '2027-01-01 00:00:00', 0], [$loaded->uptimeCode, $loaded->uptimeMs, $loaded->sitemapUrls, $loaded->sslExpiresAt, $loaded->failures]);
        self::assertSame($state->robotsText, $loaded->robotsText);
        self::assertSame([], $repo->due('uptime', '2026-11-16 09:59:00', 10), 'Checked since then.');
        self::assertCount(1, $repo->due('daily', '2026-11-16 10:00:00', 10), 'The daily check never ran.');

        $repo->setEnabled($projectId, $workspaceId, false, '2026-11-16 10:05:00');
        self::assertFalse($repo->state($projectId)->enabled);
        self::assertSame(42, $repo->state($projectId)->sitemapUrls, 'Switching off keeps the last results.');
        self::assertSame([], $repo->due('daily', '2026-11-17 00:00:00', 10));

        self::assertTrue($repo->addEvent($projectId, 'down', 'down:1', 'Down', 'HTTP 503', '2026-11-16 10:00:00'));
        self::assertFalse($repo->addEvent($projectId, 'down', 'down:1', 'Down', 'HTTP 503', '2026-11-16 10:15:00'), 'Recorded once per key.');
        $repo->addEvent($projectId, 'recovered', 'up:1', 'Up', 'ok', '2026-11-16 11:00:00');
        self::assertSame(['recovered', 'down'], array_column($repo->events($projectId, null, 10), 'kind'));
        self::assertSame(['recovered'], array_column($repo->events($projectId, '2026-11-16 10:30:00', 10), 'kind'));
        $repo->pruneEvents(1);
        self::assertSame(['recovered'], array_column($repo->events($projectId, null, 10), 'kind'));
    }
}

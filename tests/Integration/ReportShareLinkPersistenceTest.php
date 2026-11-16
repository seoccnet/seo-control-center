<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\Reports\ShareLinkRepository;

/** Read-only client links (1.3.0) on the real engine. */
final class ReportShareLinkPersistenceTest extends FullStackTestCase
{
    public function testLinksAreFoundByHashAndHiddenWithTheirProject(): void
    {
        $owner = $this->createUser('links@share.test', 'Links');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('links@share.test'), 'Links', 'links.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $repo = $this->app->make(ShareLinkRepository::class);
        $hash = hash('sha256', 'token', true);

        $link = $repo->create($owner['workspace']->id, $projectId, $hash, 'tokenX', 'Client', '2026-12-01 00:00:00', $owner['user']->id, '2026-11-16 00:00:00');
        self::assertSame($publicId, $link->projectPublicId);
        self::assertSame($link->id, $repo->byTokenHash($hash)?->id);
        self::assertNull($repo->byTokenHash(hash('sha256', 'other', true)));
        self::assertSame(1, $repo->countActive($projectId, '2026-11-20 00:00:00'));
        self::assertSame(0, $repo->countActive($projectId, '2026-12-02 00:00:00'), 'Expired links are not active.');

        $repo->recordView($link->id, '2026-11-17 08:00:00');
        $repo->revoke($link->id, '2026-11-18 00:00:00');
        $repo->revoke($link->id, '2026-11-19 00:00:00');
        $saved = $repo->find($projectId, $link->publicId) ?? self::fail('Link lost.');
        self::assertSame([1, '2026-11-17 08:00:00', '2026-11-18 00:00:00'], [$saved->views, $saved->lastViewedAt, $saved->revokedAt]);
        self::assertSame(0, $repo->countActive($projectId, '2026-11-20 00:00:00'));
        self::assertNotSame('token', (string) $this->db->fetchValue('SELECT token_hash FROM report_share_links'));

        $this->db->execute('UPDATE projects SET deleted_at = ? WHERE id = ?', ['2026-11-20 00:00:00', $projectId]);
        self::assertNull($repo->byTokenHash($hash), 'A deleted project\'s links stop working.');
        self::assertSame([], $repo->forProject($projectId, 10));
    }
}

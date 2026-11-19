<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\Backlinks\DisavowRepository;

/** The project's list of domains to disavow (1.7.0) on the real engine. */
final class LinkReviewListPersistenceTest extends FullStackTestCase
{
    public function testListIsKeptPerProject(): void
    {
        $owner = $this->createUser('links@acme.test', 'Links');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $user = $owner['user']->id;
        $project = $this->project($workspace, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f94');
        $other = $this->project($workspace, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f95');
        $repo = $this->app->make(DisavowRepository::class);

        self::assertSame(2, $repo->add($project, $workspace, ['a-site.test' => 88, 'b-site.test' => null], $user));
        self::assertSame(1, $repo->add($project, $workspace, ['a-site.test' => 10, 'c-site.test' => 300], $user), 'Listed domains are kept as they are.');
        self::assertSame(1, $repo->add($other, $workspace, ['a-site.test' => 1], $user));
        $rows = $repo->domains($project);
        self::assertSame(['a-site.test', 'b-site.test', 'c-site.test'], array_column($rows, 'domain'));
        self::assertSame([88, null, 100], array_column($rows, 'spamScore'));
        self::assertSame(2, $repo->remove($project, ['a-site.test', 'c-site.test', 'unknown.test', 'a-site.test']));
        self::assertSame(['b-site.test'], array_column($repo->domains($project), 'domain'));
        self::assertSame(['a-site.test'], array_column($repo->domains($other), 'domain'), 'Projects are separate.');
        $this->db->execute('DELETE FROM projects WHERE id = ?', [$other]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM backlink_disavows WHERE project_id = ?', [$other]));
    }

    private function project(int $workspaceId, string $publicId): int
    {
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES (?, ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$publicId, $workspaceId],
        );

        return $this->db->lastInsertId();
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\ContentBriefs\ClaimRepository;

/** ADR-042: brief claims on MySQL, versioned with every earlier revision kept. */
final class BriefClaimsDbTest extends FullStackTestCase
{
    public function testClaimsAreVersionedReviewedAndKeepTheirHistory(): void
    {
        $owner = $this->createUser('claims@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES ('0192f0a0-0000-7000-8000-000000000a01', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$workspace]);
        $project = $this->db->lastInsertId();
        $brief = '0192f0a0-0000-7000-8000-000000000a02';
        $repo = $this->app->make(ClaimRepository::class);

        $data = ['claim' => 'Brandywine reaches 25% NPK ✓', 'subject' => 'Brandywine page', 'claim_scope' => ['breeder' => 'Meadow Seeds', 'cultivar' => 'Brandywine'], 'source_url' => 'https://lab.test/a', 'publisher' => 'Lab', 'checked_on' => '2026-10-20', 'passage' => 'Table 2', 'source_scope' => ['breeder' => 'Hillside Farm'], 'status' => 'unreviewed', 'note' => ''];
        $claim = $repo->add($workspace, $project, $brief, $data, $owner['user']->id);
        $found = $repo->find($project, $claim->publicId);
        self::assertSame([['breeder' => 'Meadow Seeds', 'cultivar' => 'Brandywine'], ['breeder' => 'Hillside Farm'], '2026-10-20', 1], [$found?->claimScope, $found?->sourceScope, $found?->checkedOn, $found?->version]);
        self::assertSame(1, $repo->countForBrief($project, $brief));

        self::assertTrue($repo->update($project, $claim->publicId, 1, [...$data, 'status' => 'contradicted', 'note' => 'Other breeder'], true, $owner['user']->id));
        self::assertFalse($repo->update($project, $claim->publicId, 1, $data, false, null), 'An old version is refused.');
        $reviewed = $repo->find($project, $claim->publicId);
        self::assertSame(['contradicted', 2, $owner['user']->id], [$reviewed?->status, $reviewed?->version, $reviewed?->reviewedBy]);
        self::assertNotNull($reviewed?->reviewedAt);

        $revisions = $repo->revisions($project, $claim->publicId);
        self::assertCount(1, $revisions);
        self::assertSame(['unreviewed', 'Brandywine reaches 25% NPK ✓', 1], [$revisions[0]['snapshot']['status'], $revisions[0]['snapshot']['claim'], $revisions[0]['version']]);

        self::assertSame(1, $repo->forProject($project, 'contradicted', 0, 10)['total']);
        self::assertSame(0, $repo->forProject($project, 'supported', 0, 10)['total']);
        self::assertSame([], $repo->revisions($project + 1, $claim->publicId), 'Another project sees no history.');
        self::assertTrue($repo->remove($project, $claim->publicId));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM brief_claim_revisions'), 'Revisions go with their claim.');
    }
}

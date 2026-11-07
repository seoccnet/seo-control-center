<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Modules\Opportunities\Persistence\PdoOpportunityRepository;

final class OpportunitySnoozeTest extends FullStackTestCase
{
    public function testSnoozeKeepsIdentityAndExpiryCannotResolveAgainstMissingSources(): void
    {
        $owner = $this->createUser('snooze@acme.test', 'Snooze');
        self::assertNotNull($owner['workspace']);
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'Europe/Berlin', UTC_TIMESTAMP(), UTC_TIMESTAMP())", ['0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6fa2', $owner['workspace']->id]);
        $project = $this->db->lastInsertId();
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-08 12:00:00 UTC'));
        $repo = new PdoOpportunityRepository($this->db, $clock);
        $opportunity = new Opportunity(OpportunityType::QuickWin, 'query:shoes', 'Review', 'Review', [['label' => 'Query', 'value' => 'shoes'], ['label' => 'Required sources', 'value' => 'search_console']], 80, 90, 1, 100);
        $repo->sync($project, [$opportunity]);
        $id = $repo->catalog($project)['rows'][0]->publicId;
        self::assertTrue($repo->snooze($project, $id, '2026-10-10', $owner['user']->id));
        self::assertFalse($repo->snooze($project + 1000, $id, '2026-10-10', $owner['user']->id));
        $repo->sync($project, [$opportunity]);
        $snoozed = $repo->find($project, $id);
        self::assertNotNull($snoozed);
        self::assertSame('snoozed', $snoozed->status);
        self::assertSame('2026-10-10', $snoozed->snoozedUntil);
        $clock->advance('+1 day 11 hours'); // 23:00 UTC, already October 10 in Berlin.
        $result = $repo->sync($project, [], [], ['search_console' => ['status' => 'missing', 'date' => null]]);
        self::assertSame(0, $result['resolved']);
        $resumed = $repo->find($project, $id);
        self::assertNotNull($resumed);
        self::assertSame('open', $resumed->status);
        self::assertNull($resumed->snoozedUntil);
        self::assertSame(80, $resumed->impact);
        $history = $this->db->fetchOne('SELECT h.from_status, h.to_status, h.changed_by FROM opportunity_status_history h JOIN opportunities o ON o.id = h.opportunity_id WHERE o.project_id = ? AND o.public_id = ? ORDER BY h.id DESC LIMIT 1', [$project, $id]);
        self::assertSame(['from_status' => 'snoozed', 'to_status' => 'open', 'changed_by' => null], $history);
    }
}

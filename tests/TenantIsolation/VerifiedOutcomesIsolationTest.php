<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\OpportunityOutcome;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOutcomes;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPageWatches;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Bulk watches, the watch limit and outcome confirmations (ADR-039, E2) of
 * another workspace or an unassigned project answer 404 and change nothing.
 */
final class VerifiedOutcomesIsolationTest extends TestCase
{
    public function testForeignWatchesLimitsAndOutcomesAreNotFound(): void
    {
        $s = new Scenario();
        $outcomes = $s->container->get(InMemoryOutcomes::class);
        $watches = $s->container->get(InMemoryPageWatches::class);
        self::assertInstanceOf(InMemoryOutcomes::class, $outcomes);
        self::assertInstanceOf(InMemoryPageWatches::class, $watches);
        $row = new StoredOpportunity('0190a8f0-0000-7000-8000-000000000003', OpportunityType::QuickWin, 'Rival work', 'Review', [], 50, 80, 1, 40, 'done', '2026-10-01', '2026-10-08');
        $outcomes->record($s->rivalProject->id, $s->rival->id, $row, date('Y-m-d'), null, null, 'finding');
        $foreign = $outcomes->recent($s->rivalProject->id)[0]->publicId;

        $owner = $s->loggedIn($s->owner);
        $rivalPath = '/projects/' . $s->rivalProject->publicId;
        $ownPath = '/projects/' . $s->acmeProject->publicId;
        foreach ([[$rivalPath . '/page-monitoring/bulk', ['urls' => 'https://rival.test/a']], [$rivalPath . '/page-monitoring/limit', ['limit' => '5']], [$rivalPath . '/opportunities/outcomes/' . $foreign . '/implementation', ['implemented' => '0']], [$ownPath . '/opportunities/outcomes/' . $foreign . '/implementation', ['implemented' => '0']]] as [$path, $body]) {
            $owner->get($ownPath . '/page-monitoring');
            self::assertSame(404, $owner->post($path, $body)->getStatusCode(), $path);
        }
        $unassigned = $s->loggedIn($s->unassigned);
        $unassigned->get('/projects');
        self::assertSame(404, $unassigned->get($ownPath . '/page-monitoring')->getStatusCode());

        self::assertSame([], $watches->watches[$s->rivalProject->id] ?? []);
        self::assertSame(50, $watches->limit($s->rival->id));
        self::assertSame(OpportunityOutcome::UNVERIFIED, $outcomes->recent($s->rivalProject->id)[0]->implementation);
        self::assertStringNotContainsString('Rival work', (string) $owner->get($ownPath . '/opportunities/outcomes')->getBody());
    }
}

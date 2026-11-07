<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Opportunities\ChangeEvidence;
use SeoControlCenter\Domain\Opportunities\OpportunityConsolidator;
use SeoControlCenter\Domain\Opportunities\OpportunityOutcome;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\OutcomeCalibration;
use SeoControlCenter\Domain\Opportunities\OutcomeMetrics;
use SeoControlCenter\Domain\Opportunities\OutcomeOverlaps;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Modules\Opportunities\OutcomeService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOutcomeMeasurements;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOutcomes;
use SeoControlCenter\Tests\Support\Scenario;

/** ADR-039, E2: verified outcomes and overlap flags. */
final class VerifiedOutcomesTest extends TestCase
{
    public function testPageKeyIgnoresSchemeCaseAndTrailingSlash(): void
    {
        self::assertSame('shop.acme.test/shoes', OpportunityConsolidator::pageKey('https://Shop.Acme.test/shoes/'));
        self::assertSame('shop.acme.test/', OpportunityConsolidator::pageKey('http://shop.acme.test'));
        $row = new StoredOpportunity('id', OpportunityType::QuickWin, 'Review', 'Review', [['label' => 'Value page', 'value' => 'https://shop.acme.test/shoes/ (12 clicks)']], 50, 80, 1, 40, 'open', '2026-10-01', '2026-10-08');
        self::assertSame('https://shop.acme.test/shoes/', OpportunityConsolidator::pageUrl($row));
        self::assertSame('shop.acme.test/shoes', OpportunityConsolidator::page($row));
    }

    public function testOverlapsSkipTheOwnNoteAndChangesInsideTheVerifyWindow(): void
    {
        $row = $this->outcome('a', 'page:shop.acme.test/shoes', 'https://shop.acme.test/shoes', 'own-note');
        $overlaps = OutcomeOverlaps::find(
            $row,
            [['id' => 'own-note', 'date' => '2026-10-08', 'title' => 'Rewrote the title', 'url' => 'https://shop.acme.test/shoes'], ['id' => 'theme', 'date' => '2026-10-20', 'title' => 'Theme update', 'url' => null]],
            [$row, $this->outcome('b', 'query:shoes', 'http://shop.acme.test/shoes/', null), $this->outcome('c', 'query:boots', 'https://shop.acme.test/boots', null), $this->outcome('d', 'page:shop.acme.test/shoes', null, null)],
            [['public_id' => 'early', 'detected_at' => '2026-10-10 04:00:00', 'fields' => ['title']], ['public_id' => 'late', 'detected_at' => '2026-11-01 04:00:00', 'fields' => ['h1', 'canonical']]],
        );
        self::assertSame(['completion', 'completion', 'note', 'page_change'], array_column($overlaps, 'kind'));
        self::assertSame(['b', 'd', 'theme', 'late'], array_column($overlaps, 'ref'));
        self::assertSame('Site-wide change: Theme update', $overlaps[2]['label']);
        self::assertSame('Later change detected on the page: h1, canonical', $overlaps[3]['label']);
        self::assertFalse((new OpportunityOutcome('x', 1, 1, 'a', 'quick_win', 's', '2026-10-08', 28, null, null, null, null, null, null, null, OpportunityOutcome::VERIFIED, null, null, $overlaps))->clean());
        self::assertTrue((new OpportunityOutcome('x', 1, 1, 'a', 'quick_win', 's', '2026-10-08', 28, null, null, null, null, null, null, null, OpportunityOutcome::CONFIRMED))->clean());
        self::assertFalse($row->clean(), 'Unverified outcomes are never clean.');
    }

    public function testCalibrationPrefersFiveCleanOutcomes(): void
    {
        self::assertSame(['factor' => OutcomeCalibration::factor(1.5, 5), 'samples' => 5], OutcomeCalibration::preferClean(['quick_win' => ['clean' => [1.5, 1.5, 1.5, 1.5, 1.5], 'other' => [0.0, 0.0]]])['quick_win']);
        self::assertSame(['factor' => OutcomeCalibration::factor(1.0, 4), 'samples' => 4], OutcomeCalibration::preferClean(['quick_win' => ['clean' => [1.5, 1.5], 'other' => [0.5, 0.5]]])['quick_win']);
        self::assertSame([], OutcomeCalibration::preferClean([]));
    }

    public function testVerifyPendingAndOverlapsAfterMeasuring(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-08 12:00:00 UTC'));
        $scenario = new Scenario(clock: $clock);
        $project = $scenario->acmeProject;
        $repository = new InMemoryOutcomes($clock);
        $measurements = new InMemoryOutcomeMeasurements();
        $evidence = new class implements ChangeEvidence {
            /** @var list<array{public_id: string, detected_at: string, fields: list<string>}> */
            public array $changes = [];

            public function pageChanges(int $projectId, string $url, string $from, string $to): array
            {
                // Only the shoes page changed.
                return OpportunityConsolidator::pageKey($url) !== 'shop.acme.test/shoes' ? [] : array_values(array_filter($this->changes, static fn(array $c): bool => $c['detected_at'] >= $from && $c['detected_at'] <= $to));
            }

            public function notes(int $projectId, int $workspaceId, ?string $url, string $from, string $to): array
            {
                return array_values(array_filter([['id' => 'own-note', 'date' => '2026-10-08', 'title' => 'Rewrote the title', 'url' => $url], ['id' => 'theme', 'date' => '2026-10-20', 'title' => 'Theme update', 'url' => null]], static fn(array $n): bool => $n['date'] >= $from && $n['date'] <= $to));
            }
        };
        $service = new OutcomeService($repository, $measurements, $clock, $evidence);
        $shoes = new StoredOpportunity('finding-a', OpportunityType::QuickWin, 'Review', 'Review', [['label' => 'Page', 'value' => 'https://shop.acme.test/shoes']], 50, 80, 1, 40, 'done', '2026-10-01', '2026-10-08', 150);
        $other = new StoredOpportunity('finding-b', OpportunityType::SiteIssue, 'Refresh', 'Refresh', [['label' => 'Page', 'value' => 'https://shop.acme.test/shoes/']], 50, 80, 1, 40, 'done', '2026-10-01', '2026-10-08', 20);
        $noPage = new StoredOpportunity('finding-c', OpportunityType::QuickWin, 'Review', 'Review', [['label' => 'Query', 'value' => 'boots']], 50, 80, 1, 40, 'done', '2026-10-01', '2026-10-08', 20);
        $service->completed($project, $shoes, 'own-note', 'task');
        $service->completed($project, $other, null, 'finding');
        $service->completed($project, $noPage, null, 'finding');
        $taskPage = new StoredOpportunity('finding-d', OpportunityType::QuickWin, 'Review', 'Review', [['label' => 'Page', 'value' => 'https://shop.acme.test/old']], 50, 80, 1, 40, 'done', '2026-10-01', '2026-10-08', 20);
        $service->completed($project, $taskPage, null, 'task', 'https://shop.acme.test/new');
        self::assertSame('https://shop.acme.test/new', $this->row($repository, $project->id, 'finding-d', 28)->pageUrl, 'The team task page wins over the finding page.');

        self::assertSame(0, $service->verifyPending(), 'No page change seen yet.');
        $evidence->changes = [['public_id' => 'chg-early', 'detected_at' => '2026-10-10 04:00:00', 'fields' => ['title']]];
        $clock->advance('+2 days');
        self::assertSame(2, $service->verifyPending(), 'Both outcomes of the changed page are verified; the one without a page is not.');
        self::assertSame(0, $service->verifyPending());
        $a = $this->row($repository, $project->id, 'finding-a', 28);
        self::assertSame(OpportunityOutcome::VERIFIED, $a->implementation);
        self::assertSame('page_change:chg-early', $a->implementationSource);
        self::assertSame(OpportunityOutcome::VERIFIED, $this->row($repository, $project->id, 'finding-a', 56)->implementation, 'Both checkpoints share the state.');
        self::assertSame(OpportunityOutcome::UNVERIFIED, $this->row($repository, $project->id, 'finding-c', 28)->implementation);

        $confirmed = $service->confirm($project, $this->row($repository, $project->id, 'finding-b', 28)->publicId, false, 'user');
        self::assertSame(OpportunityOutcome::NOT_IMPLEMENTED, $confirmed?->implementation);
        self::assertNull($service->confirm($scenario->rivalProject, $a->publicId, true, 'user'), 'Another project cannot confirm it.');

        $evidence->changes[] = ['public_id' => 'chg-late', 'detected_at' => '2026-11-01 04:00:00', 'fields' => ['h1']];
        $measurements->measurements[$project->id]['page:shop.acme.test/shoes']['2026-09-10:2026-10-07'] = new OutcomeMetrics(100, 1000, 8);
        $measurements->measurements[$project->id]['page:shop.acme.test/shoes']['2026-10-09:2026-11-05'] = new OutcomeMetrics(160, 1500, 6);
        $clock->advance('+28 days');
        self::assertGreaterThanOrEqual(1, $service->measureDue());
        $measured = $this->row($repository, $project->id, 'finding-a', 28);
        self::assertSame(['completion', 'note', 'page_change'], array_column($measured->overlaps, 'kind'));
        self::assertSame(['finding-b', 'theme', 'chg-late'], array_column($measured->overlaps, 'ref'));
        self::assertFalse($measured->clean());
        self::assertSame([], $this->row($repository, $project->id, 'finding-a', 56)->overlaps, 'Day 56 is not measured yet.');
    }

    private function outcome(string $opportunity, string $subject, ?string $page, ?string $note): OpportunityOutcome
    {
        return new OpportunityOutcome('outcome-' . $opportunity, 1, 1, $opportunity, 'quick_win', $subject, '2026-10-08', 28, 50.0, null, null, $note, null, null, $page);
    }

    private function row(InMemoryOutcomes $repository, int $projectId, string $opportunity, int $checkpoint): OpportunityOutcome
    {
        foreach ($repository->recent($projectId) as $row) {
            if ($row->opportunityId === $opportunity && $row->checkpoint === $checkpoint) {
                return $row;
            }
        }
        self::fail('No outcome for ' . $opportunity);
    }
}

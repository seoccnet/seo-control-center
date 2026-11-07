<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\EffortModel;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityPrioritizer;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\PanelSnapshot;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Domain\Research\ResearchKeyword;

final class OpportunityEffortTest extends TestCase
{
    public function testKnownDifficultyAndLengthRefineContentEffortAndUnknownsKeepEstimate(): void
    {
        $opportunity = new Opportunity(OpportunityType::ContentGap, 'query:shoes', 'Review', 'Review', [['label' => 'Query', 'value' => 'shoes'], ['label' => 'Page', 'value' => 'https://shop.test/a']], 50, 0, 3);
        $coverage = ['research_ranked' => ['status' => 'available', 'date' => '2026-10-06'], 'internal_links' => ['status' => 'available', 'date' => '2026-10-06']];
        $pages = [['url' => 'https://shop.test/a', 'inbound' => 3, 'words' => 800, 'noindex' => false]];
        $easy = new ProjectSignals(researchRanked: [new ResearchKeyword('shoes', difficulty: 15)], coverage: $coverage, panel: new PanelSnapshot(auditPages: $pages));
        self::assertSame(1, (new EffortModel($easy))->assess($opportunity)['effort']);
        $hard = new ProjectSignals(researchRanked: [new ResearchKeyword('shoes', difficulty: 65)], coverage: $coverage, panel: new PanelSnapshot(auditPages: $pages));
        self::assertSame(3, (new EffortModel($hard))->assess($opportunity)['effort']);
        $pages[0]['words'] = 3500;
        self::assertSame(3, (new EffortModel(new ProjectSignals(coverage: $coverage, panel: new PanelSnapshot(auditPages: $pages))))->assess($opportunity)['effort']);
        self::assertSame(3, (new EffortModel(new ProjectSignals()))->assess($opportunity)['effort']);
    }

    public function testScopeAndFreshPrerequisitesAreExplicit(): void
    {
        $url = 'https://shop.test/a';
        $opportunity = new Opportunity(OpportunityType::QuickWin, 'query:shoes', 'Review', 'Review', [['label' => 'Page', 'value' => $url]], 50, 0, 1, urlsTouched: 11);
        $signals = new ProjectSignals(coverage: ['internal_links' => ['status' => 'available', 'date' => '2026-10-06']], panel: new PanelSnapshot(auditPages: [['url' => $url, 'inbound' => 1, 'words' => 800, 'noindex' => true, 'canonical' => 'https://shop.test/other']]));
        $assessment = (new EffortModel($signals))->assess($opportunity);
        self::assertSame(3, $assessment['effort']);
        self::assertSame('Prerequisites', $assessment['evidence'][2]['label']);
        self::assertStringContainsString('noindex/canonical', $assessment['evidence'][2]['value']);
        self::assertSame(['internal_links'], $assessment['sources']);
    }

    public function testWeeklyCapAndDependenciesPreventOneTypeDominating(): void
    {
        $rows = [];
        for ($i = 0; $i < 20; ++$i) {
            $rows[] = $this->row('content-' . $i, OpportunityType::ContentGap, 100 - $i);
        }
        foreach ([OpportunityType::Indexing, OpportunityType::LowCtr, OpportunityType::InternalLinks, OpportunityType::LocalSeo] as $i => $type) {
            $rows[] = $this->row('other-' . $i, $type, 10 - $i);
        }
        $rows[] = $this->row('blocked', OpportunityType::QuickWin, 100, [['label' => 'Prerequisites', 'value' => 'Fix indexing first']]);
        $weekly = (new OpportunityPrioritizer())->weekly($rows);
        self::assertCount(8, $weekly);
        self::assertSame(3, count(array_filter($weekly, static fn(StoredOpportunity $row): bool => $row->type === OpportunityType::ContentGap)));
        self::assertSame('blocked', $weekly[7]->publicId);
        self::assertStringContainsString('Unblocked work precedes', OpportunityPrioritizer::explanation($weekly[6], $weekly[7]));
    }

    /** @param list<array{label: string, value: string}> $evidence */
    private function row(string $id, OpportunityType $type, float $priority, array $evidence = []): StoredOpportunity
    {
        return new StoredOpportunity($id, $type, 'Review', 'Review', $evidence, 50, 80, 1, $priority, 'open', '2026-10-01', '2026-10-08');
    }
}

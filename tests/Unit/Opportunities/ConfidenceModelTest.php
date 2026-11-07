<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\ConfidenceModel;
use SeoControlCenter\Domain\Opportunities\ConfidenceObservation;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Research\ResearchKeyword;

final class ConfidenceModelTest extends TestCase
{
    public function testGoldenSampleStabilityFreshnessAgreementAndCompleteness(): void
    {
        $model = new ConfidenceModel(new ProjectSignals(
            researchRanked: [new ResearchKeyword('topic', 5000, difficulty: 20, position: 8)],
            coverage: ['search_console' => ['status' => 'available', 'date' => '2026-10-06'], 'rankings' => ['status' => 'available', 'date' => '2026-10-06'], 'research_ranked' => ['status' => 'available', 'date' => '2026-10-06']],
            confidenceObservations: [new ConfidenceObservation('query', 'TOPIC', 'search_console', 4310, 120, 8.0, 1.2, 28, '2026-10-06'), new ConfidenceObservation('query', 'topic', 'rankings', null, null, 9.0, 0.5, 4, '2026-10-06')],
            analysisDate: '2026-10-08',
            refreshDays: ['search_console' => 7, 'rankings' => 7],
        ));
        $result = $model->assess($this->finding(), ['search_console']);
        // 35 + round(5 log10(4311)) + round(15 - 3*1.2) + round(10*(1-2/7)) + 10.
        self::assertSame(81, $result['score']);
        self::assertStringContainsString('4,310 impressions, 120 clicks +18', implode('; ', $result['reasons']));
        self::assertStringContainsString('±1.2 across 28', implode('; ', $result['reasons']));
        self::assertContains('rankings', $result['sources']);
        self::assertContains('research_ranked', $result['sources']);
    }

    public function testUnknownIsExplicitAndNeverEquivalentToZeroOrStable(): void
    {
        $result = (new ConfidenceModel(new ProjectSignals()))->assess($this->finding(), ['search_console']);
        self::assertSame(10, $result['score']);
        self::assertContains('search sample unknown −5', $result['reasons']);
        self::assertContains('volume unknown −10', $result['reasons']);
        self::assertContains('difficulty unknown −5', $result['reasons']);
        self::assertContains('freshness unknown +0', $result['reasons']);
    }

    public function testVolatilityDisagreementAndAgeLowerConfidenceAndStaleSourcesAreExcluded(): void
    {
        $coverage = ['search_console' => ['status' => 'available', 'date' => '2026-10-01'], 'rankings' => ['status' => 'available', 'date' => '2026-10-01']];
        $rows = [new ConfidenceObservation('query', 'topic', 'search_console', 4310, 120, 8.0, 8.0, 28, '2026-10-01'), new ConfidenceObservation('query', 'topic', 'rankings', null, null, 20.0, 2.0, 4, '2026-10-01')];
        $result = (new ConfidenceModel(new ProjectSignals(coverage: $coverage, confidenceObservations: $rows, analysisDate: '2026-10-08', refreshDays: ['search_console' => 7, 'rankings' => 7])))->assess($this->finding(), ['search_console']);
        self::assertSame(19, $result['score']);
        self::assertStringContainsString('differ by 12.0 -10', implode('; ', $result['reasons']));
        $coverage['rankings']['status'] = 'stale';
        $result = (new ConfidenceModel(new ProjectSignals(coverage: $coverage, confidenceObservations: $rows, analysisDate: '2026-10-08')))->assess($this->finding(), ['search_console']);
        self::assertNotContains('rankings', $result['sources']);
        self::assertContains('independent position agreement unknown +0', $result['reasons']);
    }

    public function testBingAgreementIsExplicitAndUsesBothSourceFootprints(): void
    {
        $signals = new ProjectSignals(coverage: ['search_console' => ['status' => 'available', 'date' => '2026-10-06'], 'bing' => ['status' => 'available', 'date' => '2026-10-06']], confidenceObservations: [new ConfidenceObservation('query', 'topic', 'search_console', 1000, 20, 8.0, 1.0, 4, '2026-10-06'), new ConfidenceObservation('query', 'topic', 'bing', 500, 10, 9.0, null, 1, '2026-10-06')], analysisDate: '2026-10-08');
        $result = (new ConfidenceModel($signals))->assess($this->finding(), ['search_console']);
        self::assertContains('bing', $result['sources']);
        self::assertStringContainsString('position sources search_console, bing differ by 1.0 +10', implode('; ', $result['reasons']));
    }

    private function finding(): Opportunity
    {
        return new Opportunity(OpportunityType::QuickWin, 'query:topic', 'Improve topic', '', [['label' => 'Query', 'value' => 'topic']], 50, 99, 1);
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\ConfidenceObservation;
use SeoControlCenter\Domain\Opportunities\DeclineModel;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\RankingDeclineRule;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\SearchConsole\Metrics;

final class DeclineModelTest extends TestCase
{
    public function testSmallDropFailsSignificanceAndSustainedDropIsExplained(): void
    {
        self::assertFalse(DeclineModel::traffic(new Metrics(14, 100, 8), new Metrics(20, 100, 8), null, null)['detect']);
        $result = DeclineModel::traffic(new Metrics(50, 1000, 8), new Metrics(100, 1000, 8), new Metrics(150, 1000, 8), null);
        self::assertTrue($result['detect']);
        self::assertContains(['label' => 'Three-period trend', 'value' => '150 → 100 → 50 clicks; sustained decline'], $result['evidence']);
        self::assertStringContainsString('z = 4.08', $result['evidence'][0]['value']);
    }

    public function testSeasonalDipIsSuppressedButDropBeyondSeasonRemains(): void
    {
        $previous = new Metrics(1000, 10000, 8);
        $seasonal = DeclineModel::traffic(new Metrics(500, 5000, 8), $previous, null, .5);
        self::assertFalse($seasonal['detect']);
        self::assertTrue($seasonal['seasonal']);
        self::assertStringContainsString('consistent with seasonal dip', $seasonal['evidence'][2]['value']);
        $unusual = DeclineModel::traffic(new Metrics(200, 2000, 8), $previous, null, .5);
        self::assertTrue($unusual['detect']);
        self::assertStringContainsString('drop exceeds seasonal context', $unusual['evidence'][2]['value']);
        $unknown = DeclineModel::traffic(new Metrics(500, 5000, 8), $previous, null, null);
        self::assertTrue($unknown['detect']);
        self::assertStringContainsString('Unknown: need 13', $unknown['evidence'][2]['value']);
    }

    public function testRankMovementWithinObservedVolatilityIsSuppressed(): void
    {
        $rule = new RankingDeclineRule();
        $move = new RankingMove('topic', 5, 10);
        $noisy = new ProjectSignals(moves: [$move], confidenceObservations: [new ConfidenceObservation('query', 'topic', 'rankings', null, null, 8.0, 3.0, 4, '2026-10-06')]);
        self::assertSame([], $rule->evaluate($noisy));
        $stable = new ProjectSignals(moves: [$move], confidenceObservations: [new ConfidenceObservation('query', 'topic', 'rankings', null, null, 8.0, 1.0, 4, '2026-10-06')]);
        self::assertCount(1, $rule->evaluate($stable));
        $unknown = new ProjectSignals(moves: [$move]);
        $found = $rule->evaluate($unknown);
        self::assertCount(1, $found);
        self::assertStringContainsString('volatility unknown', array_column($found[0]->evidence, 'value', 'label')['Decline significance']);
    }
}

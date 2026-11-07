<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Comparison;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\CompetitorAnalysisRule;
use SeoControlCenter\Domain\Rankings\Visibility;

/**
 * The click estimate of analysis actions (0.20.0 live check): the main
 * search plus a capped share of the rest, halved for a page to create.
 */
final class EstimateTest extends TestCase
{
    public function testBigTopicsDoNotCountEverySearchAsWon(): void
    {
        $gain = Visibility::ctr(3);
        $create = $this->clicks(['main' => 20000.0, 'total' => 306000.0], 'create_page', null);
        self::assertEqualsWithDelta(round($gain * (20000 + 20000) * 1.0 * 0.5, 1), $create['clicks'], 0.2, 'Main 20,000 plus at most as much again from 286,000 other demand, halved for a new page.');
        self::assertStringContainsString('for “veg seeds” (demand 20,000)', $create['gain']);
        self::assertStringContainsString('halved because the page has to be created first', $create['gain']);

        $section = $this->clicks(['main' => 2000.0, 'total' => 2300.0], 'add_section', 8);
        self::assertEqualsWithDelta(round(($gain - Visibility::ctr(8)) * (2000 + 30) * 0.8, 1), $section['clicks'], 0.2, 'A small topic: main plus 10 % of the other 300.');
        self::assertStringNotContainsString('halved', $section['gain']);

        $legacy = $this->clicks(null, 'create_page', null);
        self::assertEqualsWithDelta(round($gain * 306000 * 0.15 * 0.5, 1), $legacy['clicks'], 0.2, 'Analyses from 0.20.0 and earlier count 15 % of the topic.');
    }

    /**
     * @param array{main: float, total: float}|null $demand
     * @return array{clicks: float, gain: string}
     */
    private function clicks(?array $demand, string $type, ?int $position): array
    {
        $weight = $type === 'add_section' ? 0.8 : 1.0;
        $verdict = ['diagnosis' => ['bottleneck' => 'format', 'reasons' => [], 'actions' => [['type' => $type, 'title' => 'T', 'what' => 'W', 'evidence' => [], 'effort' => 'high', 'weight' => $weight]]]];
        if ($demand !== null) {
            $verdict['demand'] = $demand;
        }
        $analysis = new Analysis('0192f0a0-0000-7000-8000-000000000401', 1, 1, '0192f0a0-0000-7000-8000-000000000402', 'veg seeds', 'veg seeds', ['veg seeds'], $demand['total'] ?? 306000.0, Analysis::DONE, Analysis::PAID, 2840, 'en', [], $position === null ? null : 'https://shop.test/p', $position, [], $verdict, 0.002, null, '2026-10-16 10:00:00', '2026-10-16 10:05:00');
        $found = (new CompetitorAnalysisRule())->evaluate(new ProjectSignals(comparisons: [$analysis]));
        self::assertCount(1, $found);
        $gain = array_column($found[0]->evidence, 'value', 'label')['Estimated gain'];

        return ['clicks' => (float) $found[0]->estimatedClicks, 'gain' => $gain];
    }
}

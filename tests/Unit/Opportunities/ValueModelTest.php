<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\Intent;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\ValueModel;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Pages\PageQuery;

final class ValueModelTest extends TestCase
{
    public function testGoldenPageRevenueConversionPriorAndIntent(): void
    {
        $model = new ValueModel($this->signals());
        $result = $model->assess($this->finding());
        self::assertSame(58, $result['impact']);
        self::assertSame(['ga4', 'query_pages'], $result['sources']);
        self::assertContains(['label' => 'Est. extra key events', 'value' => '100.00 clicks × 0.040000 key events/session = 4.00 per month'], $result['evidence']);
        self::assertContains(['label' => 'Est. extra revenue', 'value' => '100.00 clicks × 1.666667 revenue/session = 166.67 EUR per month'], $result['evidence']);
        self::assertStringContainsString('relative to project average', array_column($result['evidence'], 'value', 'label')['Impact basis']);
        self::assertStringContainsString('Correlation, not causal attribution', array_column($result['evidence'], 'value', 'label')['Value assumption']);
    }

    public function testSessionsWithoutKeyEventsOrRevenueAreUnknownValueNotZero(): void
    {
        $signals = new ProjectSignals(coverage: ['ga4' => ['status' => 'available', 'date' => '2026-10-06']], landingPages: [['url' => '/shop', 'sessions' => 100, 'conversions' => 0.0, 'revenue' => 0.0]], queryPages: $this->signals()->queryPages, currency: 'EUR');
        $values = array_column((new ValueModel($signals))->assess($this->finding())['evidence'], 'value', 'label');

        self::assertStringStartsWith('unknown:', $values['Est. extra key events']);
        self::assertStringStartsWith('unknown:', $values['Est. extra revenue']);
        self::assertStringNotContainsString('0.00 per month', implode(' ', $values), 'No zero business value is reported for unverified measurement.');
    }

    public function testMissingStaleAndZeroSessionDataFallbackWithoutInventingConversions(): void
    {
        foreach ([new ProjectSignals(), $this->signals('stale'), $this->signals(sessions: 0)] as $signals) {
            $result = (new ValueModel($signals))->assess($this->finding());
            self::assertSame(Opportunity::impactFromClicks(125), $result['impact']);
            self::assertSame([], $result['sources']);
            self::assertNotContains('Est. extra key events', array_column($result['evidence'], 'label'));
        }
        $unknown = new Opportunity(OpportunityType::QuickWin, 'query:unknown', '', '', [], 20, 0, 1);
        self::assertSame(20, (new ValueModel($this->signals()))->assess($unknown)['impact']);
    }

    public function testForeignHostCannotMatchOwnPageByPathAndValueMultiplierIsBounded(): void
    {
        $signals = new ProjectSignals(domain: 'acme.test', coverage: ['ga4' => ['status' => 'available', 'date' => '2026-10-06']], landingPages: [['url' => 'https://foreign.test/shop', 'sessions' => 100, 'conversions' => 10.0, 'revenue' => 10000.0]], queryPages: [new PageQuery('buy shoes', 'https://acme.test/shop', new Metrics(10, 1000, 8))]);
        self::assertSame([], (new ValueModel($signals))->assess($this->finding())['sources']);
        $signals = new ProjectSignals(coverage: ['ga4' => ['status' => 'available', 'date' => '2026-10-06']], landingPages: [['url' => '/shop', 'sessions' => 1000, 'conversions' => 100.0, 'revenue' => 10000.0]], queryPages: [new PageQuery('buy shoes', 'https://acme.test/shop', new Metrics(10, 1000, 8))], ga4Totals: ['sessions' => 100000, 'conversions' => 100.0, 'revenue' => 10000.0, 'pages' => 1]);
        self::assertSame(Opportunity::impactFromClicks(500), (new ValueModel($signals))->assess($this->finding())['impact']);
    }

    public function testIntentPatternsDoNotGuessAmbiguousOrNonEnglishQueries(): void
    {
        self::assertSame('transactional', Intent::of('BUY shoes')['label']);
        self::assertSame('commercial', Intent::of('best shoes review')['label']);
        self::assertSame('informational', Intent::of('how to repair shoes')['label']);
        self::assertSame('navigational', Intent::of('customer portal login')['label']);
        self::assertSame(['label' => 'unknown', 'weight' => 1.0], Intent::of('čevapi 🍖'));
        self::assertSame('unknown', Intent::of('bookcase')['label']);
    }

    private function signals(string $status = 'available', int $sessions = 100): ProjectSignals
    {
        return new ProjectSignals(coverage: ['ga4' => ['status' => $status, 'date' => '2026-10-06']], landingPages: [['url' => '/shop', 'sessions' => $sessions, 'conversions' => 10.0, 'revenue' => 300.0]], queryPages: [new PageQuery('buy shoes', 'https://acme.test/shop', new Metrics(10, 1000, 8))], currency: 'EUR', ga4Totals: ['sessions' => 1000, 'conversions' => 10.0, 'revenue' => 1000.0, 'pages' => 10]);
    }

    private function finding(): Opportunity
    {
        return new Opportunity(OpportunityType::QuickWin, 'query:buy shoes', '', '', [['label' => 'Query', 'value' => 'buy shoes']], 50, 0, 1, 100.0);
    }
}

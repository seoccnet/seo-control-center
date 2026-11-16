<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Rankings\PositionBuckets;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Overview\OverviewCalculations;

final class OverviewCalculationsTest extends TestCase
{
    public function testMoversRankAbsoluteChangesAndDoNotInventPercentFromZero(): void
    {
        $clock = new FrozenClock(new \DateTimeImmutable('2026-10-07 12:00:00 UTC'));
        $range = DateRange::endingOn($clock->now()->modify('-1 day')->format('Y-m-d'), 28);
        self::assertSame('2026-09-09', $range->fromString());
        self::assertSame('2026-09-08', $range->previous()->toString());
        $result = OverviewCalculations::movers([
            ['key' => 'loss', 'current' => new Metrics(5, 200, 8), 'previous' => new Metrics(25, 300, 5)],
            ['key' => 'gain', 'current' => new Metrics(30, 300, 5), 'previous' => new Metrics(10, 200, 8)],
            ['key' => 'zero baseline', 'current' => new Metrics(5, 100, 2), 'previous' => new Metrics(0, 50, 2)],
            ['key' => 'unchanged', 'current' => new Metrics(10, 100, 2), 'previous' => new Metrics(10, 100, 2)],
        ]);
        self::assertSame(['gain', 'zero baseline'], array_column($result['gainers'], 'key'));
        self::assertSame(20, $result['gainers'][0]['delta']);
        self::assertEqualsWithDelta(200.0, $result['gainers'][0]['percent'], 0.001);
        self::assertNull($result['gainers'][1]['percent']);
        self::assertSame(-20, $result['losers'][0]['delta']);
        self::assertSame(['gainers' => [], 'losers' => []], OverviewCalculations::movers([]));
    }

    public function testBucketsKeepCheckedNotFoundSeparateAndRespectEveryBoundary(): void
    {
        self::assertSame(['1–3' => 2, '4–10' => 2, '11–20' => 2, '21+' => 2, 'Not found' => 1], PositionBuckets::count([1, 3, 4, 10, 11, 20, 21, 100, null]));
        self::assertSame(0, array_sum(PositionBuckets::count([])));
    }

    public function testCtrEstimateUsesOtherObservedPeersAndRanksClickGap(): void
    {
        $rows = [new DimensionRow('small', new Metrics(0, 200, 8)), new DimensionRow('large', new Metrics(2, 1000, 8))];
        for ($i = 0; $i < 6; ++$i) {
            $rows[] = new DimensionRow('peer ' . $i, new Metrics(20, 200, 5));
        }
        $rows[] = new DimensionRow('different band', new Metrics(1000, 1000, 1));
        $actions = OverviewCalculations::ctrActions($rows);
        self::assertSame('large', $actions[0]['key']);
        self::assertSame(7, $actions[0]['peers']);
        self::assertSame(1400, $actions[0]['peerImpressions']);
        self::assertEqualsWithDelta(120 / 1400, $actions[0]['baseline'], 0.00001);
        self::assertSame(83.7, $actions[0]['extraClicks']);
        self::assertSame('small', $actions[1]['key']);
        self::assertSame([], OverviewCalculations::ctrActions(array_slice($rows, 0, 4)));
    }

    public function testCtrCandidateRequiresDemandAndNoGapMeansNoAction(): void
    {
        $rows = [];
        for ($i = 0; $i < 6; ++$i) {
            $rows[] = new DimensionRow('equal ' . $i, new Metrics(20, 200, 8));
        }
        $rows[] = new DimensionRow('tiny sample', new Metrics(0, 20, 8));
        $rows[] = new DimensionRow('out of range', new Metrics(0, 1000, 30));
        self::assertSame([], OverviewCalculations::ctrActions($rows));
    }

    public function testBreakdownSharesTopRowsSumsTheRestAndComparesOnlyWithAPreviousPeriod(): void
    {
        $rows = [new DimensionRow('usa', new Metrics(30, 900, 6)), new DimensionRow('deu', new Metrics(60, 1000, 5)), new DimensionRow('fra', new Metrics(6, 300, 9)), new DimensionRow('nld', new Metrics(4, 100, 9))];
        $split = OverviewCalculations::breakdown($rows, [new DimensionRow('deu', new Metrics(40, 800, 5))], 2);
        self::assertSame('clicks', $split['metric']);
        self::assertSame(100, $split['total']);
        self::assertSame(['deu', 'usa'], array_column($split['rows'], 'key'));
        self::assertEqualsWithDelta(0.6, $split['rows'][0]['share'], 1e-9);
        self::assertSame(40, $split['rows'][0]['previousClicks']);
        self::assertSame(0, $split['rows'][1]['previousClicks'], 'Absent from a complete previous period means no clicks then.');
        self::assertSame(['count' => 2, 'clicks' => 10, 'impressions' => 400, 'share' => 0.1], $split['other']);

        $unknown = OverviewCalculations::breakdown($rows, null, 6);
        self::assertNull($unknown['rows'][0]['previousClicks'], 'No comparison without a complete previous period.');
        self::assertSame(0, $unknown['other']['count']);

        $noClicks = OverviewCalculations::breakdown([new DimensionRow('MOBILE', new Metrics(0, 300, 9)), new DimensionRow('DESKTOP', new Metrics(0, 100, 7))], null, 6);
        self::assertSame('impressions', $noClicks['metric']);
        self::assertEqualsWithDelta(0.75, $noClicks['rows'][0]['share'], 1e-9);
        self::assertSame(['metric' => 'impressions', 'total' => 0, 'rows' => [], 'other' => ['count' => 0, 'clicks' => 0, 'impressions' => 0, 'share' => 0.0]], OverviewCalculations::breakdown([], null, 6));
    }

    public function testAssistantSessionsAreSummedPerAssistantName(): void
    {
        $rows = OverviewCalculations::assistants([
            ['source' => 'perplexity.ai', 'sessions' => 8, 'key_events' => 1.0, 'revenue' => 0.0],
            ['source' => 'chatgpt.com', 'sessions' => 5, 'key_events' => 0.5, 'revenue' => 0.0],
            ['source' => 'chat.openai.com', 'sessions' => 4, 'key_events' => 0.0, 'revenue' => 0.0],
            ['source' => 'unknown.example', 'sessions' => 1, 'key_events' => 0.0, 'revenue' => 0.0],
        ]);
        self::assertSame([['assistant' => 'ChatGPT', 'sessions' => 9, 'keyEvents' => 0.5], ['assistant' => 'Perplexity', 'sessions' => 8, 'keyEvents' => 1.0], ['assistant' => 'unknown.example', 'sessions' => 1, 'keyEvents' => 0.0]], $rows);
        self::assertSame([], OverviewCalculations::assistants([]));
    }

    public function testCountryNamesComeFromSearchConsoleCodes(): void
    {
        self::assertSame('Germany', \SeoControlCenter\Domain\International\CountryCodes::name('deu'));
        self::assertSame('United States', \SeoControlCenter\Domain\International\CountryCodes::name('USA'));
        self::assertSame('Russia', \SeoControlCenter\Domain\International\CountryCodes::name('rus'));
        self::assertSame('Unknown region', \SeoControlCenter\Domain\International\CountryCodes::name('zzz'));
        self::assertSame('QQQ', \SeoControlCenter\Domain\International\CountryCodes::name('qqq'));
    }
}

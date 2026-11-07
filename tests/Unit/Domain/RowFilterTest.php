<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\SearchConsole\FilteredRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\RowFilter;

final class RowFilterTest extends TestCase
{
    public function testTextWordsAndMetricBounds(): void
    {
        $filter = new RowFilter([' How To ', 'how to', 'what is'], exclude: ['Acme'], minImpressions: 100, maxCtr: 0.02, minWords: 3);
        self::assertSame(['how to', 'what is'], $filter->contains, 'Terms are trimmed, lowercased and de-duplicated.');
        self::assertTrue($filter->matchesKey('How to fix a bike'));
        self::assertFalse($filter->matchesKey('how to acme'), 'Excluded terms win.');
        self::assertFalse($filter->matchesKey('what is'), 'Too few words.');
        self::assertTrue($filter->matchesMetrics(new Metrics(1, 100, 9.0)));
        self::assertFalse($filter->matchesMetrics(new Metrics(3, 100, 9.0)), 'CTR above the bound.');
        self::assertFalse($filter->matchesMetrics(new Metrics(0, 99, 9.0)));
        self::assertFalse((new RowFilter(maxPosition: 10.0))->matchesMetrics(Metrics::zero()), 'No impressions means no position.');
        self::assertTrue((new RowFilter(['a', 'b'], containsAll: true))->matchesKey('b then a'));
        self::assertFalse((new RowFilter(['a', 'b'], containsAll: true))->matchesKey('only a'));
        self::assertSame(3, RowFilter::words('  three word query '));
    }

    public function testChangeSortsAndPresenceCompareAutomatically(): void
    {
        self::assertFalse((new RowFilter())->compare);
        self::assertTrue((new RowFilter(sort: 'clicks_change'))->compare);
        self::assertTrue((new RowFilter(presence: 'new'))->compare);
        $new = new RowFilter(presence: 'new');
        self::assertTrue($new->matches(new FilteredRow('q', new Metrics(1, 10, 3.0), Metrics::zero())));
        self::assertFalse($new->matches(new FilteredRow('q', new Metrics(1, 10, 3.0), new Metrics(0, 1, 3.0))));
        self::assertTrue((new RowFilter(presence: 'lost'))->matches(new FilteredRow('q', Metrics::zero(), new Metrics(0, 5, 3.0))));

        $losers = new RowFilter(sort: 'clicks_change', descending: false);
        $rows = [new FilteredRow('b', new Metrics(5, 50, 3.0), new Metrics(6, 50, 3.0)), new FilteredRow('a', new Metrics(1, 50, 3.0), new Metrics(9, 50, 3.0))];
        usort($rows, $losers->compare(...));
        self::assertSame(['a', 'b'], array_map(static fn(FilteredRow $r): string => $r->key, $rows));
    }

    public function testInvalidCriteriaAreRejected(): void
    {
        foreach ([
            static fn(): RowFilter => new RowFilter(sort: 'volume'),
            static fn(): RowFilter => new RowFilter(maxCtr: 1.5),
            static fn(): RowFilter => new RowFilter(minPosition: 0.5),
            static fn(): RowFilter => new RowFilter(minImpressions: -1),
            static fn(): RowFilter => new RowFilter(array_map('strval', range(1, 11))),
            static fn(): RowFilter => new RowFilter([str_repeat('x', 101)]),
            static fn(): RowFilter => new RowFilter(minWords: 21),
            static fn(): RowFilter => new RowFilter(presence: 'gone'),
        ] as $index => $make) {
            $rejected = false;
            try {
                $make();
            } catch (\InvalidArgumentException) {
                $rejected = true;
            }
            self::assertTrue($rejected, 'Case ' . $index . ' should be rejected.');
        }
    }
}

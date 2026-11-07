<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\SignalCoverage;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities;

final class SignalCoverageTest extends TestCase
{
    public function testFreshnessBoundariesAndFutureData(): void
    {
        $now = new \DateTimeImmutable('2026-10-08');
        self::assertSame('missing', SignalCoverage::at(null, $now, 7)['status']);
        self::assertSame('available', SignalCoverage::at('2026-10-01', $now, 7)['status']);
        self::assertSame('stale', SignalCoverage::at('2026-09-30', $now, 7)['status']);
        self::assertSame('stale', SignalCoverage::at('2026-10-09', $now, 7)['status']);
        self::assertFalse(SignalCoverage::complete([], ['rankings']));
    }

    public function testMissingSourcePreservesScoresAndFreshAbsenceResolves(): void
    {
        $repo = new InMemoryOpportunities(new FrozenClock(new \DateTimeImmutable('2026-10-08')));
        $make = static fn(int $impact): Opportunity => new Opportunity(OpportunityType::LowCtr, 'query:shoes', 'Title', 'Why', [['label' => 'Required sources', 'value' => 'search_console, bing']], $impact, 70, 1);
        $fresh = ['search_console' => ['status' => 'available', 'date' => '2026-10-06'], 'bing' => ['status' => 'available', 'date' => '2026-10-06']];
        $repo->sync(1, [$make(50)], ['low_ctr'], $fresh);
        $id = $repo->list(1, 'open', null, 1, 0)[0]->publicId;
        $missing = $fresh;
        $missing['bing'] = ['status' => 'missing', 'date' => null];
        self::assertSame(['opened' => 0, 'updated' => 0, 'resolved' => 0], $repo->sync(1, [$make(90)], [], $missing));
        $stored = $repo->find(1, $id);
        self::assertNotNull($stored);
        self::assertSame(50, $stored->impact);
        self::assertSame(0, $repo->sync(1, [], [], $missing)['resolved']);
        $stored = $repo->find(1, $id);
        self::assertNotNull($stored);
        self::assertSame('open', $stored->status);
        self::assertSame(1, $repo->sync(1, [], ['low_ctr'], $fresh)['resolved']);
        $stored = $repo->find(1, $id);
        self::assertNotNull($stored);
        self::assertSame('resolved', $stored->status);
        self::assertNull($repo->find(2, $id));
        self::assertSame([], $repo->coverage(2));
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpResult;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;
use SeoControlCenter\Domain\Rankings\Visibility;

final class RankingModelTest extends TestCase
{
    public function testVisibilityIsVolumeWeightedShareOfFirstPositionClicks(): void
    {
        self::assertSame(100.0, Visibility::percent([['position' => 1, 'volume' => 500], ['position' => 1, 'volume' => null]]));
        self::assertSame(0.0, Visibility::percent([['position' => null, 'volume' => 1000], ['position' => 25, 'volume' => 10]]));
        self::assertSame(0.0, Visibility::percent([]));

        // Position 1 for a 900-volume keyword, not ranking for a 100-volume keyword: 90 %.
        self::assertSame(90.0, Visibility::percent([['position' => 1, 'volume' => 900], ['position' => null, 'volume' => 100]]));
        // Position 3 everywhere: 0.11 / 0.28.
        self::assertEqualsWithDelta(39.2857, Visibility::percent([['position' => 3, 'volume' => 10]]), 0.0001);
        self::assertSame(0.01, Visibility::ctr(15));
        self::assertSame(0.0, Visibility::ctr(21));
    }

    public function testSnapshotFindsOwnDomainIncludingSubdomainsAndKeepsTopResults(): void
    {
        $serp = new SerpSnapshot('red shoes', KeywordLocale::default(), SerpDevice::Mobile, '2026-09-26 10:00:00', 1000, [
            new SerpResult(1, 1, 'https://rival.test/', 'rival.test', null, null),
            new SerpResult(2, 3, 'https://notacme.test/', 'notacme.test', null, null),
            new SerpResult(3, 5, 'https://www.acme.test/red', 'www.acme.test', null, null),
            new SerpResult(4, 6, 'https://acme.test/other', 'acme.test', null, null),
        ], ['featured_snippet']);

        $snapshot = RankSnapshot::fromSerp(7, 3, 'acme.test', '2026-09-26', $serp, 2);

        self::assertSame(3, $snapshot->position, 'notacme.test is not a subdomain of acme.test.');
        self::assertSame(5, $snapshot->absolutePosition);
        self::assertSame('https://www.acme.test/red', $snapshot->url);
        self::assertSame(['featured_snippet'], $snapshot->features);
        self::assertSame([['position' => 1, 'domain' => 'rival.test', 'url' => 'https://rival.test/'], ['position' => 2, 'domain' => 'notacme.test', 'url' => 'https://notacme.test/']], $snapshot->top);
        self::assertSame(SerpDevice::Mobile, $snapshot->device);

        self::assertNull(RankSnapshot::fromSerp(7, 3, 'other.test', '2026-09-26', $serp)->position);
    }

    public function testChangesTreatNotRankingAsPosition101(): void
    {
        self::assertSame(4, (new RankingMove('a', 9, 5))->change());
        self::assertSame(-91, (new RankingMove('a', 10, null))->change());
        self::assertSame(81, (new RankingMove('a', null, 20))->change());

        $locale = KeywordLocale::default();
        self::assertNull((new RankedKeyword('id', 'a', $locale, null, null, null, null, null, null, [], null))->change(), 'Never checked.');
        self::assertNull((new RankedKeyword('id', 'a', $locale, '2026-09-26', 4, null, false, 4, null, [], null))->change(), 'First check.');
        self::assertSame(2, (new RankedKeyword('id', 'a', $locale, '2026-09-26', 4, 6, true, 4, null, [], null))->change());
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\Crawler\SrcsetCandidates;

final class SrcsetCandidatesTest extends TestCase
{
    public function testExtractsUrlTokensWithoutSplittingCommasInsideUrls(): void
    {
        self::assertSame(['/small.png', '/large.png'], SrcsetCandidates::urls(" , /small.png 1x,\n/large.png 2x"));
        self::assertSame(['/one.png', '/two.png'], SrcsetCandidates::urls('/one.png, /two.png'));
        self::assertSame(['data:image/png;base64,AAA', '/image?crop=1,2'], SrcsetCandidates::urls('data:image/png;base64,AAA 1x, /image?crop=1,2 2x'));
        self::assertSame(['/one.png', '/two.png'], SrcsetCandidates::urls('/one.png invalid(a,b) , /two.png 640w'));
    }

    public function testOversizedDeclarationsAreOmittedAndCandidateCountIsBounded(): void
    {
        self::assertSame([], SrcsetCandidates::urls(str_repeat('x', 2049)));
        self::assertSame([], SrcsetCandidates::urls(" , \t\r\n"));
        self::assertCount(40, SrcsetCandidates::urls(str_repeat('/image.png 1x, ', 60)));
        self::assertSame(['/last.png'], SrcsetCandidates::urls('/last.png,'));
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Rankings;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Rankings\RankingCsv;

final class RankingCsvTest extends TestCase
{
    public function testLongFormatWithDatesUrlsAndDevices(): void
    {
        $csv = RankingCsv::parse("\xEF\xBB\xBFKeyword;Position;Date;URL;Device\nrain barrel;3;2026-10-01;https://acme.test/barrel;Mobile\n\"garden, hose\";>100;01.10.2026;;desktop\nshovel;-;;;\n");

        self::assertSame('long', $csv['layout']);
        self::assertSame(['keyword' => 'keyword', 'position' => 'position', 'date' => 'date', 'url' => 'url', 'device' => 'device'], $csv['columns']);
        self::assertSame([
            ['keyword' => 'rain barrel', 'date' => '2026-10-01', 'position' => 3, 'url' => 'https://acme.test/barrel', 'device' => 'mobile'],
            ['keyword' => 'garden, hose', 'date' => '2026-10-01', 'position' => null, 'url' => null, 'device' => 'desktop'],
            ['keyword' => 'shovel', 'date' => null, 'position' => null, 'url' => null, 'device' => null],
        ], $csv['rows']);
    }

    public function testAhrefsUtf16TabExport(): void
    {
        $text = "Keyword\tVolume\tCurrent position\tCurrent URL\nregenfass\t1300\t7\thttps://acme.test/regenfass\n";
        $csv = RankingCsv::parse("\xFF\xFE" . mb_convert_encoding($text, 'UTF-16LE', 'UTF-8'));

        self::assertSame('current position', $csv['columns']['position']);
        self::assertSame([['keyword' => 'regenfass', 'date' => null, 'position' => 7, 'url' => 'https://acme.test/regenfass', 'device' => null]], $csv['rows']);
    }

    public function testWideFormatWithOneColumnPerDate(): void
    {
        $csv = RankingCsv::parse("Keyword,2026-10-01,20261008,Oct 15, 2026\nrain barrel,4,5,\n");

        self::assertSame('wide', $csv['layout']);
        self::assertSame([['2026-10-01', 4], ['2026-10-08', 5]], array_map(static fn(array $r): array => [$r['date'], $r['position']], array_slice($csv['rows'], 0, 2)));
    }

    public function testKeywordListsAndRefusals(): void
    {
        self::assertSame('keywords', RankingCsv::parse("Query\nrain barrel\nhose\n")['layout']);
        self::assertSame(['keyword' => 'café', 'date' => null, 'position' => null, 'url' => null, 'device' => null], RankingCsv::parse("Keyword\n" . mb_convert_encoding('café', 'Windows-1252', 'UTF-8') . "\n")['rows'][0], 'Windows-1252 is converted.');
        foreach (['3.0' => 3, '0' => null, '101' => null, 'n/a' => null, '' => null, '12' => 12] as $in => $out) {
            self::assertSame($out, RankingCsv::position((string) $in), (string) $in);
        }
        self::assertNull(RankingCsv::date('2026-02-30'));
        $this->expectException(\InvalidArgumentException::class);
        RankingCsv::parse("Position,URL\n3,https://acme.test/\n");
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Assistant;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Mcp\Server\BoundedResult;

final class BoundedResultTest extends TestCase
{
    public function testLargeUnicodeResultsKeepCoverageAndUsefulContinuation(): void
    {
        $data = ['project_id' => 'public-project', 'range' => ['from' => '2026-09-01', 'to' => '2026-09-28'], 'total' => 900, 'offset' => 25, 'next_offset' => 225, 'coverage' => ['imported_days' => 24, 'expected_days' => 28], 'rows' => array_map(static fn(int $i): array => ['url' => 'https://demo.test/' . $i, 'note' => str_repeat('čž漢字', 200)], range(1, 200))];
        $bounded = BoundedResult::limit($data);
        $json = BoundedResult::json($bounded);
        self::assertLessThanOrEqual(16000, strlen($json));
        self::assertSame($bounded, json_decode($json, true, flags: JSON_THROW_ON_ERROR));
        self::assertSame($data['coverage'], $bounded['coverage']);
        self::assertSame($data['range'], $bounded['range']);
        self::assertSame(900, $bounded['total']);
        self::assertTrue($bounded['result_limits']['truncated']);
        self::assertSame(25 + count($bounded['rows']), $bounded['next_offset']);
        self::assertSame(200, $bounded['result_limits']['fields']['rows']['original_count']);
    }

    public function testSmallEvidenceIsNotChangedAndHugeTextIsMarked(): void
    {
        self::assertSame(['count' => 0, 'coverage' => null], BoundedResult::limit(['count' => 0, 'coverage' => null]));
        $text = BoundedResult::limit(['note' => str_repeat('界', 30000)]);
        self::assertTrue($text['result_limits']['fields']['note']['text_truncated']);
        self::assertLessThanOrEqual(16000, strlen(BoundedResult::json($text)));
        $list = BoundedResult::limit(array_fill(0, 500, ['title' => str_repeat('x', 200)]));
        self::assertSame(500, $list['total']);
        self::assertTrue($list['result_limits']['truncated']);
    }

    public function testNestedListsAndUnicodeGuideChunksKeepTheirActualOffset(): void
    {
        $rows = array_fill(0, 50, ['keyword' => str_repeat('界', 400)]);
        $bounded = BoundedResult::limit(['ai_overviews' => ['offset' => 10, 'next_offset' => 60, 'total' => 100, 'keywords' => $rows]]);
        self::assertSame(10 + count($bounded['ai_overviews']['keywords']), $bounded['ai_overviews']['next_offset']);
        self::assertLessThan(50, count($bounded['ai_overviews']['keywords']));
        $guide = BoundedResult::limit(['text' => str_repeat('😀', 6000), 'offset' => 25, 'next_offset' => 6025, 'total_characters' => 9000]);
        self::assertLessThan(6000, mb_strlen($guide['text']));
        self::assertSame(25 + mb_strlen($guide['text']), $guide['next_offset']);
        self::assertLessThanOrEqual(16000, strlen(BoundedResult::json($guide)));
    }
}

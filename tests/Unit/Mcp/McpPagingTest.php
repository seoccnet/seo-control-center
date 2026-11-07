<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Mcp;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Mcp\Server\BoundedResult;
use SeoControlCenter\Modules\Mcp\Server\Paging;
use SeoControlCenter\Modules\Mcp\Server\ToolError;

/**
 * MCP lists over the response limit stay fully reachable through
 * offset/next_offset, and lists that cannot be paged never ask for an offset.
 */
final class McpPagingTest extends TestCase
{
    public function testEveryRecordIsReachableWhenPagesExceedTheResponseLimit(): void
    {
        $ideas = array_map(static fn(int $i): array => ['key' => 'idea-' . $i, 'notes' => str_repeat('ž', 900)], range(1, 120));
        $seen = [];
        $offset = 0;
        for ($calls = 0; $calls < 200; $calls++) {
            $page = Paging::slice($ideas, ['offset' => $offset, 'limit' => 50], 10, 50);
            $result = BoundedResult::limit(['project_id' => 'p', 'offset' => $page['offset'], 'limit' => $page['limit'], 'total' => $page['total'], 'next_offset' => $page['next_offset'], 'ideas' => $page['rows']], 16000);
            self::assertLessThanOrEqual(16000, strlen(BoundedResult::json($result)));
            self::assertNotSame([], $result['ideas'], 'Every page returns at least one record.');
            foreach ($result['ideas'] as $idea) {
                $seen[] = $idea['key'];
            }
            if (isset($result['result_limits'])) {
                self::assertStringContainsString('offset = next_offset', $result['result_limits']['message']);
                self::assertStringNotContainsString('cannot page', $result['result_limits']['message']);
            }
            if ($result['next_offset'] === null) {
                break;
            }
            $offset = $result['next_offset'];
        }
        self::assertSame(array_column($ideas, 'key'), $seen, 'All 120 records, in order, without gaps or repeats.');
    }

    public function testListsWithoutOffsetSayToNarrowInsteadOfAdvancingAnOffset(): void
    {
        $result = BoundedResult::limit(['project_id' => 'p', 'briefs' => array_fill(0, 200, ['title' => str_repeat('x', 300)]), 'page' => 1], 16000);

        self::assertTrue($result['result_limits']['truncated']);
        self::assertStringNotContainsString('offset', $result['result_limits']['message']);
        self::assertStringContainsString('cannot page briefs', $result['result_limits']['message']);
        self::assertArrayNotHasKey('next_offset', $result);
    }

    public function testMixedResponsesNameWhichListsContinue(): void
    {
        $result = BoundedResult::limit(['offset' => 0, 'next_offset' => null, 'total' => 80, 'plans' => array_fill(0, 80, ['n' => str_repeat('a', 400)]), 'published' => array_fill(0, 80, ['n' => str_repeat('b', 400)])], 16000);

        self::assertStringContainsString('To continue plans', $result['result_limits']['message']);
        self::assertStringContainsString('cannot page published', $result['result_limits']['message']);
        self::assertSame(count($result['plans']), $result['next_offset']);
    }

    public function testSliceBoundsAndValidatesArguments(): void
    {
        $rows = range(1, 7);
        self::assertSame(['rows' => [6, 7], 'offset' => 5, 'limit' => 3, 'total' => 7, 'next_offset' => null], Paging::slice($rows, ['offset' => 5, 'limit' => 3], 10, 50));
        self::assertSame(3, Paging::slice($rows, ['offset' => 0, 'limit' => 3], 10, 50)['next_offset']);
        self::assertSame(50, Paging::slice(range(1, 80), ['limit' => 500], 10, 50)['limit'], 'limit is capped.');
        self::assertSame([], Paging::slice($rows, ['offset' => 99], 10, 50)['rows']);
        $this->expectException(ToolError::class);
        Paging::slice($rows, ['offset' => '5'], 10, 50);
    }
}

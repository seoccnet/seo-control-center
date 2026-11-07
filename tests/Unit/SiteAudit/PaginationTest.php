<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\Crawler\Pagination;

final class PaginationTest extends TestCase
{
    public function testRecognisesPageNumbers(): void
    {
        self::assertSame(3, Pagination::pageNumber('https://shop.test/list?page=3'));
        self::assertSame(2, Pagination::pageNumber('https://shop.test/list?sort=new&paged=2'));
        self::assertNull(Pagination::pageNumber('https://shop.test/list?page=all'));
        self::assertNull(Pagination::pageNumber('https://shop.test/list'));
    }

    public function testFollowsOnlyTheFirstPagesOfASeries(): void
    {
        self::assertTrue(Pagination::follow('https://shop.test/list'));
        self::assertTrue(Pagination::follow('https://shop.test/list?page=10'));
        self::assertFalse(Pagination::follow('https://shop.test/list?page=11'));
    }

    public function testSeriesDropsPageAndOffsetParameters(): void
    {
        self::assertSame('https://shop.test/list', Pagination::series('https://shop.test/list?page=7'));
        self::assertSame('https://shop.test/list?sort=new', Pagination::series('https://shop.test/list?sort=new&p=2&offset=40'));
        self::assertSame('https://shop.test/list?type=herb', Pagination::series('https://shop.test/list?type=herb'));
    }
}

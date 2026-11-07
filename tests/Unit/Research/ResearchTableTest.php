<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Research;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Research\ResearchDomain;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Modules\Research\ResearchTable;

final class ResearchTableTest extends TestCase
{
    /**
     * @return list<ResearchKeyword>
     */
    private static function keywords(): array
    {
        return [
            new ResearchKeyword('red shoes', 9900, 1.2, 45, 'commercial', 3, null, 800.0),
            new ResearchKeyword('blue shoes', 1300, null, 20, 'informational', 12, null, 40.0),
            new ResearchKeyword('green boots', null, null, null, null, null),
            new ResearchKeyword('shoe size', 22000, 0.2, 5, 'informational', 40, null, 60.0),
        ];
    }

    public function testDefaultAndColumnSorting(): void
    {
        $byTraffic = ResearchTable::fromQuery([], 'r', ResearchTable::KEYWORD_COLUMNS, 'traffic', 'desc');
        self::assertSame(['red shoes', 'shoe size', 'blue shoes', 'green boots'], self::names($byTraffic->keywords(self::keywords())), 'empty values last');

        $byPosition = ResearchTable::fromQuery(['r_sort' => 'position'], 'r', ResearchTable::KEYWORD_COLUMNS, 'traffic', 'desc');
        self::assertSame('asc', $byPosition->dir, 'positions read best first');
        self::assertSame(['red shoes', 'blue shoes', 'shoe size', 'green boots'], self::names($byPosition->keywords(self::keywords())));

        $volumeAsc = ResearchTable::fromQuery(['r_sort' => 'volume', 'r_dir' => 'asc'], 'r', ResearchTable::KEYWORD_COLUMNS, 'traffic', 'desc');
        self::assertSame(['blue shoes', 'red shoes', 'shoe size', 'green boots'], self::names($volumeAsc->keywords(self::keywords())));
        self::assertSame(' ▲', $volumeAsc->arrow('volume'));
        self::assertSame('', $volumeAsc->arrow('keyword'));
        self::assertSame(['r_sort' => 'volume', 'r_dir' => 'desc'], $volumeAsc->sortParams('volume'), 'clicking the sorted column reverses it');
        self::assertSame(['r_sort' => 'difficulty', 'r_dir' => 'asc'], $volumeAsc->sortParams('difficulty'));

        $unknown = ResearchTable::fromQuery(['r_sort' => 'evil', 'r_dir' => 'sideways'], 'r', ResearchTable::KEYWORD_COLUMNS, 'traffic', 'desc');
        self::assertSame(['traffic', 'desc'], [$unknown->sort, $unknown->dir]);
    }

    public function testFilters(): void
    {
        $table = ResearchTable::fromQuery(['r_q' => ' SHOE', 'r_vmin' => '1000', 'r_dmax' => '30', 'r_pmax' => 'abc', 'g_q' => 'other table'], 'r', ResearchTable::KEYWORD_COLUMNS, 'volume', 'desc');
        self::assertSame(['q' => 'SHOE', 'vmin' => '1000', 'dmax' => '30'], $table->filters, 'invalid numbers are ignored');
        self::assertSame(['shoe size', 'blue shoes'], self::names($table->keywords(self::keywords())));
        self::assertSame(['r_sort' => 'volume', 'r_dir' => 'desc', 'r_q' => 'SHOE', 'r_vmin' => '1000', 'r_dmax' => '30'], $table->params());

        $positions = ResearchTable::fromQuery(['r_pmin' => '10', 'r_pmax' => '50'], 'r', ResearchTable::KEYWORD_COLUMNS, 'volume', 'desc');
        self::assertSame(['shoe size', 'blue shoes'], self::names($positions->keywords(self::keywords())), 'unranked keywords drop out of a position filter');
    }

    public function testDomains(): void
    {
        $domains = [new ResearchDomain('a.test', 100, null, null, 50.0, 10, 20.5), new ResearchDomain('b.test', 900, null, null, 10.0, 30, 8.0), new ResearchDomain('c.test')];

        $shared = ResearchTable::fromQuery([], 'c', ResearchTable::DOMAIN_COLUMNS, 'shared', 'desc');
        self::assertSame(['b.test', 'a.test', 'c.test'], array_map(static fn(ResearchDomain $d): string => $d->domain, $shared->domains($domains)));
        $average = ResearchTable::fromQuery(['c_sort' => 'average'], 'c', ResearchTable::DOMAIN_COLUMNS, 'shared', 'desc');
        self::assertSame(['b.test', 'a.test', 'c.test'], array_map(static fn(ResearchDomain $d): string => $d->domain, $average->domains($domains)));
        $traffic = ResearchTable::fromQuery(['c_sort' => 'traffic'], 'c', ResearchTable::DOMAIN_COLUMNS, 'shared', 'desc');
        self::assertSame(['a.test', 'b.test', 'c.test'], array_map(static fn(ResearchDomain $d): string => $d->domain, $traffic->domains($domains)));
    }

    /**
     * @param list<ResearchKeyword> $keywords
     * @return list<string>
     */
    private static function names(array $keywords): array
    {
        return array_map(static fn(ResearchKeyword $k): string => $k->keyword, $keywords);
    }
}

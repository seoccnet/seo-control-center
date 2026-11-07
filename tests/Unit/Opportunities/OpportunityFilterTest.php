<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\OpportunityFilter;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;

final class OpportunityFilterTest extends TestCase
{
    public function testFiltersUseExactNormalizedLandingPathAndRecordedSources(): void
    {
        $row = new StoredOpportunity('id', OpportunityType::QuickWin, 'Review', 'Review', [['label' => 'Page', 'value' => 'https://shop.test/shoes/?campaign=a'], ['label' => 'Required sources', 'value' => 'rankings, search_console, ga4'], ['label' => 'Keyword gap supporting: Required sources', 'value' => 'research_gap']], 75, 80, 2, 30, 'open', '2026-10-01', '2026-10-08');
        self::assertTrue(OpportunityFilter::fromArray(['page_url' => '/shoes', 'min_impact' => '75', 'effort' => '2', 'data_basis' => 'ga4'])->matches($row));
        self::assertTrue(OpportunityFilter::fromArray(['data_basis' => 'research'])->matches($row));
        self::assertFalse(OpportunityFilter::fromArray(['page_url' => '/shoe'])->matches($row));
        self::assertFalse(OpportunityFilter::fromArray(['page_url' => 'https://foreign.test/shoes'])->matches($row));
        self::assertFalse(OpportunityFilter::fromArray(['min_impact' => 76])->matches($row));
        self::assertFalse(OpportunityFilter::fromArray(['effort' => 1])->matches($row));
        self::assertFalse(OpportunityFilter::fromArray(['data_basis' => 'bing'])->matches($row));
    }

    public function testInvalidFilterIsRejectedRatherThanSilentlyBroadened(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OpportunityFilter::fromArray(['min_impact' => -1]);
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Pages;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Pages\PageInsights;
use SeoControlCenter\Modules\Pages\PageQuery;
use SeoControlCenter\Modules\SiteAudit\AuditPage;

final class PageInsightsTest extends TestCase
{
    public function testCannibalizationNeedsMeaningfulEvidenceOnMoreThanOnePage(): void
    {
        $rows = [new PageQuery('seo audit', 'https://site.test/a', new Metrics(8, 150, 6)),
            new PageQuery('seo audit', 'https://site.test/b', new Metrics(3, 60, 10)),
            new PageQuery('seo audit', 'https://site.test/c', new Metrics(0, 5, 18)),
            new PageQuery('tiny query', 'https://site.test/a', new Metrics(1, 50, 6)),
            new PageQuery('tiny query', 'https://site.test/b', new Metrics(1, 50, 6))];
        $result = (new PageInsights())->cannibalization($rows);
        self::assertCount(1, $result);
        self::assertSame('seo audit', $result[0]['query']);
        self::assertSame(['https://site.test/a', 'https://site.test/b'], array_column($result[0]['pages'], 'url'));
    }

    public function testLinkSuggestionsExcludeExistingEdgesAndUnusableDonors(): void
    {
        $page = static fn(int $id, string $title, bool $noindex = false, ?string $canonical = null): AuditPage => new AuditPage($id, 'https://site.test/' . $id, 1, 'done', 200, 'text/html', title: $title, noindex: $noindex, canonical: $canonical);
        $target = $page(1, 'SEO audit');
        $pages = [$target, $page(2, 'SEO audit guide'), $page(3, 'SEO audit checklist'), $page(4, 'SEO audit', true), $page(5, 'SEO audit', canonical: 'https://site.test/1'), $page(6, 'Cooking'), $page(7, 'SEO audit')];
        $result = (new PageInsights())->internalLinks($target, $pages, [1 => 4, 2 => 8, 3 => 6, 4 => 20, 5 => 30, 6 => 50, 7 => 1], ['https://site.test/2'], ['seo audit']);
        self::assertSame(['https://site.test/3'], array_column($result, 'url'));
        self::assertSame([], (new PageInsights())->internalLinks($target, $pages, [3 => 6], [], []));
    }
}

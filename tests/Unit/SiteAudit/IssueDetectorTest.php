<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\SiteAudit;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\IssueDetector;
use SeoControlCenter\Modules\SiteAudit\IssueType;

final class IssueDetectorTest extends TestCase
{
    public function testDeniedAndRateLimitedPagesAreNotClaimedMissing(): void
    {
        foreach ([401, 403, 429] as $status) {
            $result = IssueDetector::detect([new AuditPage(1, 'https://shop.acme.test/', 0, 'done', $status)], []);
            self::assertSame(IssueType::FetchRefused, $result['issues'][0]['type']);
            self::assertSame(0, $result['counts']['error']);
            self::assertSame(1, $result['counts']['warning']);
        }
    }
    public function testFindsIssuesAndScores(): void
    {
        $good = static fn(int $id, string $path, string $title, string $description): AuditPage => new AuditPage(
            $id,
            'https://shop.acme.test' . $path,
            1,
            'done',
            200,
            'text/html',
            durationMs: 300,
            title: $title,
            description: $description,
            h1Count: 1,
            wordCount: 600,
        );
        $pages = [
            $good(1, '/', 'Acme garden shop', 'Tools for every garden.'),
            $good(2, '/a', 'Same title here', 'First description.'),
            $good(3, '/b', 'Same title here', 'Second description.'),
            new AuditPage(4, 'https://shop.acme.test/gone', 1, 'done', 404, 'text/html'),
            new AuditPage(5, 'https://shop.acme.test/old', 1, 'done', 301, null, 'https://shop.acme.test/older'),
            new AuditPage(6, 'https://shop.acme.test/older', 2, 'done', 302, null, 'https://shop.acme.test/'),
            new AuditPage(7, 'http://shop.acme.test/insecure', 1, 'skipped', error: 'Not fetched'),
            new AuditPage(8, 'https://shop.acme.test/admin', 1, 'blocked'),
            new AuditPage(9, 'https://shop.acme.test/thin', 1, 'done', 200, 'text/html', durationMs: 2500, title: str_repeat('Long title ', 7), h1Count: 0, wordCount: 50),
            new AuditPage(10, 'https://shop.acme.test/private', 1, 'done', 200, 'text/html', title: null, noindex: true, wordCount: 5),
            new AuditPage(11, 'https://shop.acme.test/print', 1, 'done', 200, 'text/html', title: 'Same title here', canonical: 'https://shop.acme.test/a'),
            new AuditPage(12, 'https://shop.acme.test/down', 1, 'done', error: 'Timeout'),
            new AuditPage(13, 'https://shop.acme.test/file.pdf', 1, 'done', 200, 'application/pdf'),
            new AuditPage(14, 'https://shop.acme.test/later', 1, 'pending'),
        ];

        $result = IssueDetector::detect($pages, [4 => 3, 7 => 1]);
        $found = [];
        foreach ($result['issues'] as $issue) {
            $found[$issue['pageId']][] = $issue['type'];
            if ($issue['type'] === IssueType::BrokenPage) {
                self::assertSame('HTTP 404 · linked from 3 pages', $issue['detail']);
            }
        }

        self::assertArrayNotHasKey(1, $found);
        self::assertSame([IssueType::DuplicateTitle], $found[2]);
        self::assertSame([IssueType::DuplicateTitle], $found[3]);
        self::assertSame([IssueType::BrokenPage], $found[4]);
        self::assertSame([IssueType::RedirectChain], $found[5]);
        self::assertSame([IssueType::Redirect], $found[6]);
        self::assertSame([IssueType::HttpUrl], $found[7]);
        self::assertSame([IssueType::BlockedByRobots], $found[8]);
        self::assertSame([IssueType::SlowPage, IssueType::TitleTooLong, IssueType::MissingDescription, IssueType::MissingH1, IssueType::ThinContent], $found[9]);
        self::assertSame([IssueType::Noindex], $found[10]);
        self::assertSame([IssueType::CanonicalElsewhere], $found[11]);
        self::assertSame([IssueType::Unreachable], $found[12]);
        self::assertArrayNotHasKey(13, $found);
        self::assertArrayNotHasKey(14, $found);

        self::assertSame(['error' => 2, 'warning' => 7, 'notice' => 6], $result['counts']);
        // 13 checked addresses: 2 with errors count fully, 5 with warnings only count half.
        self::assertSame(65, $result['score']);
    }

    public function testDuplicateDetailNamesTheOtherPages(): void
    {
        $pages = [];
        foreach (['/a', '/b', '/c', '/d', '/e'] as $i => $path) {
            $pages[] = new AuditPage($i + 1, 'https://shop.acme.test' . $path, 1, 'done', 200, 'text/html', title: 'One title for all', description: 'Description ' . $i, h1Count: 1, wordCount: 500);
        }
        $details = array_column(IssueDetector::detect($pages, [])['issues'], 'detail', 'pageId');

        self::assertSame('Same title as /b, /c and 2 more', $details[1]);
        self::assertSame('Same title as /a, /b and 2 more', $details[5]);
    }

    public function testNoFetchedPagesHasNoScore(): void
    {
        $result = IssueDetector::detect([new AuditPage(1, 'https://shop.acme.test/', 0, 'blocked')], []);

        self::assertNull($result['score']);
        self::assertSame(IssueType::BlockedByRobots, $result['issues'][0]['type']);
    }

    public function testPaginatedSeriesCountsAsOnePage(): void
    {
        $page = static fn(int $id, string $path, int $depth = 1): AuditPage => new AuditPage(
            $id,
            'https://shop.acme.test' . $path,
            $depth,
            'done',
            200,
            'text/html',
            title: 'Lemon cultivars',
            description: 'Cultivars rich in lemon.',
            h1Count: 1,
            wordCount: 500,
        );
        $series = [$page(1, '/lemon'), $page(2, '/lemon?page=2'), $page(3, '/lemon?page=3')];

        self::assertSame([], IssueDetector::detect($series, [])['issues']);

        $result = IssueDetector::detect([...$series, $page(4, '/flavours'), $page(5, '/orphan', AuditPage::UNLINKED)], []);
        $byPage = [];
        foreach ($result['issues'] as $issue) {
            $byPage[$issue['pageId']][] = $issue['type'];
        }
        self::assertSame([IssueType::DuplicateTitle, IssueType::DuplicateDescription], $byPage[1]);
        self::assertArrayNotHasKey(2, $byPage);
        self::assertSame([IssueType::DuplicateTitle, IssueType::DuplicateDescription], $byPage[4]);
        self::assertSame([IssueType::NotLinked, IssueType::DuplicateTitle, IssueType::DuplicateDescription], $byPage[5]);
        self::assertSame('Same description as /flavours, /lemon', $result['issues'][count($result['issues']) - 1]['detail']);

        // When the page limit was hit, an unlinked page may be linked from a page that was not crawled.
        $partial = IssueDetector::detect([$page(5, '/orphan', AuditPage::UNLINKED)], [], false);
        self::assertSame([], $partial['issues']);
    }

    public function testGrowthChecksUseRecordedSignalsOnly(): void
    {
        $page = self::growthPage(...);
        $result = IssueDetector::detect([
            $page(1, '/', 0, ['Organization'], null, null),
            $page(2, '/product', 1, [], 'product', false),
            $page(3, '/blog/post', 2, ['BlogPosting'], 'article', false),
            $page(4, '/blog/signed', 2, ['Article'], null, true),
            $page(5, '/a/b/c/deep', 4, ['WebPage'], null, false),
            $page(6, '/sitemap-only', AuditPage::UNLINKED, ['WebPage'], null, false),
            $page(7, '/legacy', 5, null, null, null),
            $page(8, '/seeds?page=7', 6, [], null, false),
        ], [1 => 0, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1, 8 => 1], false);
        $found = [];
        foreach ($result['issues'] as $issue) {
            $found[$issue['type']->value][$issue['pageId']] = $issue['detail'];
        }
        self::assertSame([2 => 'Marked as a product (og:type); add Product markup with price and availability'], $found['missing_structured_data'] ?? null);
        self::assertSame([3], array_keys($found['missing_author'] ?? []), 'Only article pages without an author; unknown signals are not missing.');
        self::assertSame([5 => '4 clicks from the start page', 7 => '5 clicks from the start page'], $found['deep_page'] ?? null, 'Sitemap-only pages and later pages of a paginated listing are not reported.');
        self::assertSame('notice', IssueType::MissingStructuredData->severity());
        self::assertSame(100, $result['score'], 'Growth notices do not lower the health score.');
    }

    /** @param list<string>|null $types */
    private static function growthPage(int $id, string $path, int $depth, ?array $types, ?string $og, ?bool $author): AuditPage
    {
        return new AuditPage(
            $id,
            'https://shop.acme.test' . $path,
            $depth,
            'done',
            200,
            'text/html',
            null,
            100,
            1000,
            false,
            'A good title for ' . $path,
            'A useful description for ' . $path,
            1,
            'Heading',
            null,
            false,
            500,
            3,
            0,
            null,
            $types,
            $og,
            $author,
        );
    }

    public function testLongTitlesAreRankedByTheClicksTheyMiss(): void
    {
        $model = new \SeoControlCenter\Domain\Opportunities\CtrModel([3 => ['clicks' => 10000, 'impressions' => 100000], 8 => ['clicks' => 3000, 'impressions' => 100000]]);
        $title = str_repeat('Strawberry cultivar seeds with a very long name ', 2);
        $row = static fn(string $url, int $clicks, int $impressions, float $position): \SeoControlCenter\Domain\SearchConsole\DimensionRow => new \SeoControlCenter\Domain\SearchConsole\DimensionRow($url, new \SeoControlCenter\Domain\SearchConsole\Metrics($clicks, $impressions, $position));
        $result = \SeoControlCenter\Modules\SiteAudit\TitleTriage::rank([
            ['url' => 'https://shop.test/tastes/strawberry', 'title' => $title],
            ['url' => 'https://shop.test/tastes/grape/', 'title' => $title],
            ['url' => 'https://shop.test/seeds/blue', 'title' => $title],
            ['url' => 'https://shop.test/seeds/red', 'title' => $title],
            ['url' => 'https://shop.test/guides/far', 'title' => $title],
            ['url' => 'https://shop.test/', 'title' => $title],
        ], [
            $row('https://www.shop.test/tastes/strawberry', 10, 1000, 3.2),
            $row('https://shop.test/tastes/grape', 120, 1000, 3.0),
            $row('https://shop.test/seeds/blue', 2, 400, 7.6),
            $row('https://shop.test/seeds/red', 1, 99, 3.0),
            $row('https://shop.test/guides/far', 0, 500, 14.0),
        ], $model);

        $strawberry = (int) round(1000 * $model->expected(3)) - 10;
        $blue = (int) round(400 * $model->expected(8)) - 2;
        self::assertSame([6, 2], [$result['total'], $result['without_data']], 'the home page has no data; 99 impressions are too few');
        self::assertSame(['/tastes/strawberry', '/seeds/blue'], array_map(static fn(array $p): string => (string) parse_url($p['url'], PHP_URL_PATH), $result['pages']), 'grape already gets more clicks than expected; page two is left out');
        self::assertSame([$strawberry, $blue], array_column($result['pages'], 'shortfall'));
        self::assertSame([96, 3.2, 1000, 10], [$result['pages'][0]['length'], $result['pages'][0]['position'], $result['pages'][0]['impressions'], $result['pages'][0]['clicks']]);
        self::assertSame([
            ['template' => '/tastes/…', 'titles' => 2, 'measured' => 2, 'shortfall' => $strawberry],
            ['template' => '/seeds/…', 'titles' => 2, 'measured' => 1, 'shortfall' => $blue],
            ['template' => '/', 'titles' => 1, 'measured' => 0, 'shortfall' => 0],
            ['template' => '/guides/…', 'titles' => 1, 'measured' => 0, 'shortfall' => 0],
        ], $result['groups']);
        self::assertSame(['/', '/giveaways', '/seeds/…'], [\SeoControlCenter\Modules\SiteAudit\TitleTriage::template('https://shop.test'), \SeoControlCenter\Modules\SiteAudit\TitleTriage::template('https://shop.test/giveaways/'), \SeoControlCenter\Modules\SiteAudit\TitleTriage::template('https://shop.test/seeds/a/b')]);
    }

    public function testMissingBusinessDetailsAreReportedOnceOnTheStartPage(): void
    {
        $empty = ['types' => [], 'name' => null, 'phones' => [], 'address' => null];
        $page = self::businessPage(...);
        $found = self::businessIssues(...);

        $only = $found([$page(1, '/', 0, $empty, ['WebSite']), $page(2, '/seeds/a', 1, $empty, ['Product'])]);
        self::assertCount(1, $only);
        self::assertSame([1, 'Not found on the home page (no contact page found): Organization or LocalBusiness markup, a phone number (tel: link), an address in structured data'], [$only[0]['pageId'], $only[0]['detail']]);

        $withContact = $found([$page(1, '/', 0, $empty, ['WebSite']), $page(3, '/pages/kontakt/', 1, ['types' => [], 'name' => null, 'phones' => ['+4433123456'], 'address' => null])]);
        self::assertSame('Not found on the home page and 1 contact page: Organization or LocalBusiness markup, an address in structured data', $withContact[0]['detail'], 'the contact page’s tel: link counts');

        self::assertSame([], $found([$page(1, '/', 0, ['types' => ['OnlineStore'], 'name' => 'Acme', 'phones' => ['061222333'], 'address' => 'Main St 1'], ['OnlineStore', 'WebSite'])]));
        self::assertSame([], $found([$page(1, '/', 0, $empty, ['Organization']), $page(4, '/contact', 1, ['types' => ['Store'], 'name' => 'Acme', 'phones' => ['061222333'], 'address' => 'Main St 1'], ['Store'])]), 'details may be split over the home and contact pages');
        self::assertSame([], $found([$page(1, '/', 0, null), $page(4, '/contact', 1, $empty)]), 'a start page crawled before business details were read is unknown');
    }

    /**
     * @param array{types: list<string>, name: ?string, phones: list<string>, address: ?string}|null $business
     * @param list<string> $schema
     */
    private static function businessPage(int $id, string $path, int $depth, ?array $business, array $schema = []): AuditPage
    {
        return new AuditPage($id, 'https://shop.acme.test' . $path, $depth, 'done', 200, 'text/html; charset=utf-8', title: 'A page title of fair length', description: str_repeat('d', 80), h1Count: 1, wordCount: 500, schemaTypes: $schema, business: $business);
    }

    /**
     * @param list<AuditPage> $pages
     * @return list<array{pageId: int, type: IssueType, detail: ?string}>
     */
    private static function businessIssues(array $pages): array
    {
        return array_values(array_filter(IssueDetector::detect($pages, [])['issues'], static fn(array $i): bool => $i['type'] === IssueType::MissingBusinessDetails));
    }
}

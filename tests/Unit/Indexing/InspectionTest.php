<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Indexing;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Indexing\Inspection;

final class InspectionTest extends TestCase
{
    /** @return array<string, mixed> Google's indexStatusResult with the richResultsResult beside it */
    private static function google(): array
    {
        return [
            'verdict' => 'PASS',
            'coverageState' => 'Submitted and indexed',
            'googleCanonical' => 'https://shop.test/a',
            'userCanonical' => 'https://shop.test/b',
            'sitemap' => ['https://shop.test/sitemap.xml', 'ftp://shop.test/x'],
            'referringUrls' => ['https://blog.test/1', 'https://blog.test/2', 'https://blog.test/3', 'https://blog.test/4', 'https://blog.test/5', 'https://blog.test/6'],
            'richResultsResult' => [
                'verdict' => 'PARTIAL',
                'detectedItems' => [
                    ['richResultType' => 'Product snippets', 'items' => [
                        ['name' => 'Shoe', 'issues' => [['issueMessage' => 'Missing field "offers"', 'severity' => 'ERROR'], ['issueMessage' => "Missing field \"review\"\x07", 'severity' => 'WARNING']]],
                        ['name' => 'Boot', 'issues' => [['issueMessage' => 'Missing field "offers"', 'severity' => 'ERROR']]],
                    ]],
                    ['richResultType' => 'Breadcrumbs', 'items' => [['name' => 'Path']]],
                ],
            ],
        ];
    }

    public function testReadsSitemapsReferrersAndRichResultIssues(): void
    {
        $inspection = Inspection::fromArray(self::google());

        self::assertSame(['https://shop.test/sitemap.xml'], $inspection->sitemaps, 'Only HTTP(S) URLs are kept.');
        self::assertCount(5, $inspection->referringUrls, 'A sample, capped at five.');
        self::assertSame('PARTIAL', $inspection->richVerdict);
        self::assertSame(['Product snippets', 'Breadcrumbs'], array_column($inspection->richItems, 'type'));
        self::assertSame([
            ['message' => 'Missing field "offers"', 'severity' => 'ERROR'],
            ['message' => 'Missing field "review"', 'severity' => 'WARNING'],
        ], $inspection->richItems[0]['issues'], 'Issues are deduplicated across items; control characters are removed.');
        self::assertTrue($inspection->richResultErrors());
        self::assertTrue($inspection->canonicalMismatch());
    }

    public function testStoredFormRoundTrips(): void
    {
        $inspection = Inspection::fromArray(self::google());
        $stored = json_decode(json_encode($inspection->present(), JSON_THROW_ON_ERROR), true);
        self::assertIsArray($stored);

        self::assertEquals($inspection, Inspection::fromArray($stored));
    }

    public function testOlderStoredResultsAndMissingCanonicalsAreNotProblems(): void
    {
        $old = Inspection::fromArray(['verdict' => 'PASS', 'googleCanonical' => 'https://shop.test/a/', 'userCanonical' => 'https://shop.test/a']);
        self::assertFalse($old->canonicalMismatch(), 'A trailing slash is not a different canonical.');
        self::assertFalse($old->richResultErrors());
        self::assertSame([], $old->richItems);
        self::assertFalse(Inspection::fromArray(['verdict' => 'PASS', 'googleCanonical' => 'https://shop.test/a'])->canonicalMismatch(), 'No declared canonical is no mismatch.');
    }
}

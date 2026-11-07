<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\PageMonitoring;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\PageMonitoring\PageSnapshot;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\PageResult;

final class PageSnapshotTest extends TestCase
{
    private function html(string $body, int $status = 200, bool $noindex = false): PageSnapshot
    {
        $snapshot = PageSnapshot::fromResult(new PageResult('done', $status, 'text/html', page: HtmlPage::parse($body, 'https://shop.test/page'), noindex: $noindex));
        self::assertNotNull($snapshot);
        return $snapshot;
    }

    public function testCompleteMetadataAndAllSampledHeadingsAreCompared(): void
    {
        $before = $this->html('<title>Before</title><meta name="description" content="Original"><link rel="canonical" href="/page"><h1>First</h1><h1>Second</h1>');
        $after = $this->html('<title>After</title><meta name="description" content="New"><link rel="canonical" href="/other"><h1>First</h1><h1>Changed</h1>', noindex: true);
        self::assertSame(['title', 'description', 'h1s', 'canonical', 'noindex'], array_keys($before->changes($after)));
        self::assertTrue($before->significant($after));
        self::assertSame($after->values, PageSnapshot::decode($after->encode())->values);
        self::assertSame([], $after->changes($after));
        $text = $this->html('<title>Changed</title><h1>New</h1>');
        self::assertFalse($this->html('<title>Before</title><h1>Old</h1>')->significant($text));
    }

    public function testUnavailableAndNonHtmlEvidenceNeverInventsRemovedMetadata(): void
    {
        foreach ([new PageResult('blocked'), new PageResult('done', error: 'Timeout'), new PageResult('done', 200, truncated: true)] as $result) {
            self::assertNull(PageSnapshot::fromResult($result));
        }
        $before = $this->html('<title>Keep</title><h1>Keep</h1>');
        $redirect = PageSnapshot::fromResult(new PageResult('done', 301, redirectTo: 'https://other.test/'));
        self::assertNotNull($redirect);
        self::assertSame(['status'], array_keys($before->changes($redirect)));
        self::assertTrue($before->significant($redirect));
        $error = PageSnapshot::fromResult(new PageResult('done', 503));
        self::assertNotNull($error);
        self::assertSame(['status'], array_keys($before->changes($error)));
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\LabTests;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\LabTests\LabDiagnostics;
use SeoControlCenter\Domain\LabTests\LabResult;

/** Speed diagnostics (ADR-042, 0.38.0): why a run scored as it did, from the report it already received. */
final class LabDiagnosticsTest extends TestCase
{
    public function testReadsTheLcpElementBlockingResourcesDiagnosticsRequestsAndConfiguration(): void
    {
        $d = LabDiagnostics::fromLighthouse(self::report());
        self::assertSame(['element' => 'Hero photo of a tomato plant', 'snippet' => '<img class="hero" src="/media/hero.webp" alt="Hero">', 'resource' => 'https://shop.test/media/hero.webp', 'phases' => [['phase' => 'TTFB', 'ms' => 620], ['phase' => 'Load Delay', 'ms' => 900], ['phase' => 'Load Time', 'ms' => 700], ['phase' => 'Render Delay', 'ms' => 192]], 'checks' => []], $d->lcp);
        self::assertSame([['url' => 'https://shop.test/app.css', 'wasted_ms' => 480, 'bytes' => 52000], ['url' => 'https://shop.test/fonts.css', 'wasted_ms' => 150, 'bytes' => 3000]], $d->blocking);
        self::assertSame(['render-blocking-resources', 'unused-javascript', 'uses-long-cache-ttl'], array_column($d->diagnostics, 'id'), 'Largest saving first; passing audits and metrics are left out.');
        self::assertSame(['FCP' => 480, 'LCP' => 480], $d->diagnostics[0]['savings']);
        self::assertSame('Potential savings of 120 KiB', $d->diagnostics[1]['display']);
        self::assertSame([['type' => 'Total', 'requests' => 3, 'bytes' => 335000], ['type' => 'Image', 'requests' => 1, 'bytes' => 210000], ['type' => 'Script', 'requests' => 1, 'bytes' => 125000]], $d->resources);
        self::assertSame(['https://shop.test/media/hero.webp', 'https://shop.test/app.js', 'https://shop.test/seeds'], array_column($d->largest, 'url'));
        self::assertSame(1200, $d->largest[0]['ms']);
        self::assertSame(['form_factor' => 'mobile', 'screen' => '412×823 @1.75x', 'throttling_method' => 'simulate', 'rtt_ms' => 150.0, 'throughput_kbps' => 1638.4, 'cpu_slowdown' => 4.0, 'benchmark_index' => 1500.0, 'user_agent' => 'Mozilla/5.0 Chrome-Lighthouse', 'fetch_time' => '2026-10-20 10:00:00', 'final_url' => 'https://shop.test/seeds', 'channel' => 'lr'], $d->config);
        self::assertSame(64, strlen($d->report['sha256']));
        self::assertGreaterThan(1000, $d->report['bytes']);
    }

    public function testNewerAndOlderReportShapesAndHostileText(): void
    {
        $newer = self::report();
        unset($newer['audits']['resource-summary'], $newer['audits']['render-blocking-resources']);
        $newer['audits']['render-blocking-insight'] = ['details' => ['items' => [['url' => 'https://shop.test/app.css', 'wastedMs' => 300, 'totalBytes' => 52000], ['url' => 'javascript:alert(1)', 'wastedMs' => 900]]]];
        $newer['audits']['largest-contentful-paint-element']['details'] = ['type' => 'table', 'items' => [['node' => ['selector' => "div.title\x07", 'snippet' => '<h1 class="title">Seeds</h1>']]]];
        $d = LabDiagnostics::fromLighthouse($newer);
        self::assertSame([['url' => 'https://shop.test/app.css', 'wasted_ms' => 300, 'bytes' => 52000]], $d->blocking, 'Only http(s) addresses are kept.');
        self::assertSame(['element' => 'div.title', 'snippet' => '<h1 class="title">Seeds</h1>', 'resource' => null, 'phases' => [], 'checks' => []], $d->lcp, 'A text element has no image address; control characters are removed.');
        self::assertSame('Total', $d->resources[0]['type'], 'Without the summary, requests are counted from the network list.');

        self::assertNull(LabDiagnostics::fromLighthouse(['audits' => []])->lcp);
        self::assertSame([], LabDiagnostics::fromLighthouse(['audits' => 'broken', 'categories' => 7])->diagnostics);
        $result = LabResult::fromLighthouse([...self::report(), 'audits' => [...self::report()['audits'], 'network-requests' => ['details' => ['items' => 'broken']]]], 'https://shop.test/seeds', 'mobile');
        self::assertNotNull($result->diagnostics, 'A broken part of the report does not fail the run.');
    }

    public function testLighthouse13ReadsTheLcpFromItsInsightsAndListsContextApart(): void
    {
        $d = LabDiagnostics::fromLighthouse(self::lighthouse13());
        self::assertSame(['element' => 'Hero photo', 'snippet' => '<img class="hero" src="https://cdn.shop.test/hero.webp" fetchpriority="low">', 'resource' => 'https://cdn.shop.test/hero.webp', 'phases' => [['phase' => 'Time to first byte', 'ms' => 610], ['phase' => 'Resource load delay', 'ms' => 4100], ['phase' => 'Resource load duration', 'ms' => 900], ['phase' => 'Element render delay', 'ms' => 1925]], 'checks' => [['label' => 'fetchpriority=high should be applied', 'passed' => false], ['label' => 'Request is discoverable in initial document', 'passed' => true], ['label' => 'lazy load not applied', 'passed' => true]]], $d->lcp);
        self::assertSame(['render-blocking-resources', 'unused-javascript', 'uses-long-cache-ttl'], array_column($d->diagnostics, 'id'), 'The LCP insights are shown in the LCP section, not ranked.');
        self::assertSame(['Network dependency tree'], $d->also, 'Context without a saving or value is listed apart.');
        self::assertSame([null, null, null], [$d->config['throttling_method'], $d->config['cpu_slowdown'], $d->config['screen']]);
        self::assertEquals($d->present(), LabDiagnostics::fromArray(json_decode((string) json_encode($d->present(), JSON_PRESERVE_ZERO_FRACTION), true))?->present());
        $stored = $d->present();
        unset($stored['also_flagged'], $stored['lcp']['checks']);
        self::assertSame([], LabDiagnostics::fromArray($stored)?->also, 'Diagnostics stored by 0.38.0 read back without the newer parts.');
    }

    public function testStoredDiagnosticsReadBackTheSameAfterJson(): void
    {
        $d = LabDiagnostics::fromLighthouse(self::report());
        $json = (string) json_encode($d->present(), JSON_PRESERVE_ZERO_FRACTION);
        self::assertEquals($d->present(), LabDiagnostics::fromArray(json_decode($json, true))?->present());
        self::assertNull(LabDiagnostics::fromArray(['config' => []]));
        self::assertNull(LabDiagnostics::fromArray('nope'));
    }

    public function testRunsAreComparedOnlyWithTheSameLighthouseMajorAndThrottling(): void
    {
        $a = LabDiagnostics::fromLighthouse(self::report());
        self::assertNull(LabDiagnostics::incompatible('12.3.0', $a, '12.8.1', $a));
        self::assertSame('Lighthouse 12.8.1 and 13.0.0 score differently', LabDiagnostics::incompatible('12.8.1', $a, '13.0.0', $a));
        $slower = self::report();
        $slower['configSettings']['throttling']['cpuSlowdownMultiplier'] = 6;
        self::assertSame('different CPU slowdown', LabDiagnostics::incompatible('12.3.0', $a, '12.3.0', LabDiagnostics::fromLighthouse($slower)));
        self::assertNull(LabDiagnostics::incompatible('12.3.0', null, '12.3.0', $a), 'Runs before 0.38.0 compare by version only.');
        self::assertNull(LabDiagnostics::incompatible(null, null, '12.3.0', null));
        $old = new LabResult(0.9, 1800.0, 0.02, null, null, null, '11.0.0');
        self::assertSame('Lighthouse 11.0.0 and 12.3.0 score differently', $old->incompatibleWith(LabResult::fromLighthouse(self::report(), 'https://shop.test/seeds', 'mobile')));
    }

    /** @return array<string, mixed> a PageSpeed Insights report from Lighthouse 13 (0.38.1) */
    public static function lighthouse13(): array
    {
        $report = self::report();
        unset($report['audits']['largest-contentful-paint-element']);
        // PageSpeed Insights returns only part of the settings: no throttling and no screen.
        $report['configSettings'] = ['formFactor' => 'mobile', 'locale' => 'en-US', 'onlyCategories' => ['performance'], 'channel' => 'lr'];
        $report['lighthouseVersion'] = '13.5.0';
        $hero = ['type' => 'node', 'lhId' => 'page-0-IMG', 'selector' => 'main > img.hero', 'nodeLabel' => 'Hero photo', 'snippet' => '<img class="hero" src="https://cdn.shop.test/hero.webp" fetchpriority="low">'];
        $report['audits']['lcp-breakdown-insight'] = ['title' => 'LCP breakdown', 'score' => 0, 'scoreDisplayMode' => 'metricSavings', 'details' => ['type' => 'list', 'items' => [
            ['type' => 'table', 'headings' => [], 'items' => [['subpart' => 'timeToFirstByte', 'label' => 'Time to first byte', 'duration' => 610.2], ['subpart' => 'resourceLoadDelay', 'label' => 'Resource load delay', 'duration' => 4100], ['subpart' => 'resourceLoadDuration', 'label' => 'Resource load duration', 'duration' => 900], ['subpart' => 'elementRenderDelay', 'label' => 'Element render delay', 'duration' => 1925]]],
            $hero,
        ]]];
        $report['audits']['lcp-discovery-insight'] = ['title' => 'LCP request discovery', 'score' => 0, 'scoreDisplayMode' => 'metricSavings', 'details' => ['type' => 'list', 'items' => [
            ['type' => 'checklist', 'items' => ['priorityHinted' => ['label' => 'fetchpriority=high should be applied', 'value' => false], 'requestDiscoverable' => ['label' => 'Request is discoverable in initial document', 'value' => true], 'eagerlyLoaded' => ['label' => 'lazy load not applied', 'value' => true]]],
            $hero,
        ]]];
        $report['audits']['network-dependency-tree-insight'] = ['title' => 'Network dependency tree', 'score' => 0, 'scoreDisplayMode' => 'metricSavings', 'metricSavings' => ['LCP' => 0]];
        $report['categories']['performance']['auditRefs'] = [...$report['categories']['performance']['auditRefs'], ['id' => 'lcp-breakdown-insight', 'weight' => 0, 'group' => 'insights'], ['id' => 'lcp-discovery-insight', 'weight' => 0, 'group' => 'insights'], ['id' => 'network-dependency-tree-insight', 'weight' => 0, 'group' => 'insights']];

        return $report;
    }

    /** @return array<string, mixed> */
    public static function report(): array
    {
        return [
            'requestedUrl' => 'https://shop.test/seeds',
            'finalDisplayedUrl' => 'https://shop.test/seeds',
            'lighthouseVersion' => '12.3.0',
            'fetchTime' => '2026-10-20T12:00:00.000+02:00',
            'configSettings' => ['formFactor' => 'mobile', 'throttlingMethod' => 'simulate', 'throttling' => ['rttMs' => 150, 'throughputKbps' => 1638.4, 'cpuSlowdownMultiplier' => 4], 'screenEmulation' => ['mobile' => true, 'width' => 412, 'height' => 823, 'deviceScaleFactor' => 1.75, 'disabled' => false], 'channel' => 'lr'],
            'environment' => ['networkUserAgent' => 'Mozilla/5.0 Chrome-Lighthouse', 'benchmarkIndex' => 1500],
            'categories' => ['performance' => ['score' => 0.71, 'auditRefs' => [
                ['id' => 'largest-contentful-paint', 'weight' => 25, 'group' => 'metrics'],
                ['id' => 'render-blocking-resources', 'weight' => 0],
                ['id' => 'unused-javascript', 'weight' => 0],
                ['id' => 'uses-long-cache-ttl', 'weight' => 0],
                ['id' => 'uses-text-compression', 'weight' => 0],
                ['id' => 'largest-contentful-paint-element', 'weight' => 0],
            ]]],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => 2412.0, 'score' => 0.6, 'scoreDisplayMode' => 'numeric', 'title' => 'Largest Contentful Paint'],
                'cumulative-layout-shift' => ['numericValue' => 0.05],
                'render-blocking-resources' => ['title' => 'Eliminate render-blocking resources', 'score' => 0.3, 'scoreDisplayMode' => 'metricSavings', 'displayValue' => 'Potential savings of 480 ms', 'metricSavings' => ['FCP' => 480, 'LCP' => 480], 'details' => ['items' => [['url' => 'https://shop.test/fonts.css', 'wastedMs' => 150.2, 'totalBytes' => 3000], ['url' => 'https://shop.test/app.css', 'wastedMs' => 480, 'totalBytes' => 52000]]]],
                'unused-javascript' => ['title' => 'Reduce unused JavaScript', 'score' => 0.5, 'scoreDisplayMode' => 'metricSavings', 'displayValue' => 'Potential savings of 120 KiB', 'metricSavings' => ['LCP' => 300]],
                'uses-long-cache-ttl' => ['title' => 'Serve static assets with an efficient cache policy', 'score' => 0.5, 'scoreDisplayMode' => 'metricSavings', 'displayValue' => '4 resources found', 'metricSavings' => []],
                'uses-text-compression' => ['title' => 'Enable text compression', 'score' => 1, 'scoreDisplayMode' => 'metricSavings'],
                'largest-contentful-paint-element' => ['title' => 'Largest Contentful Paint element', 'score' => 0, 'scoreDisplayMode' => 'informative', 'details' => ['type' => 'list', 'items' => [
                    ['type' => 'table', 'items' => [['node' => ['type' => 'node', 'selector' => 'main > img.hero', 'nodeLabel' => 'Hero photo of a tomato plant', 'snippet' => '<img class="hero" src="/media/hero.webp" alt="Hero">']]]],
                    ['type' => 'table', 'items' => [['phase' => 'TTFB', 'percent' => '26%', 'timing' => 620.4], ['phase' => 'Load Delay', 'percent' => '37%', 'timing' => 900], ['phase' => 'Load Time', 'percent' => '29%', 'timing' => 700], ['phase' => 'Render Delay', 'percent' => '8%', 'timing' => 191.6]]],
                ]]],
                'resource-summary' => ['details' => ['items' => [['resourceType' => 'total', 'label' => 'Total', 'requestCount' => 3, 'transferSize' => 335000], ['resourceType' => 'image', 'label' => 'Image', 'requestCount' => 1, 'transferSize' => 210000], ['resourceType' => 'script', 'label' => 'Script', 'requestCount' => 1, 'transferSize' => 125000]]]],
                'network-requests' => ['details' => ['items' => [
                    ['url' => 'https://shop.test/seeds', 'resourceType' => 'Document', 'transferSize' => 18000, 'networkRequestTime' => 0, 'networkEndTime' => 640],
                    ['url' => 'https://shop.test/media/hero.webp', 'resourceType' => 'Image', 'transferSize' => 210000, 'networkRequestTime' => 900, 'networkEndTime' => 2100],
                    ['url' => 'https://shop.test/app.js', 'resourceType' => 'Script', 'transferSize' => 125000, 'networkRequestTime' => 700, 'networkEndTime' => 1500],
                ]]],
            ],
        ];
    }
}

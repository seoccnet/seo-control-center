<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\LabTests;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\LabTests\LabResult;

/** ADR-040, F2: one parser for PageSpeed Insights and DataForSEO Lighthouse reports. */
final class LabResultTest extends TestCase
{
    public function testReadsScoreAndMetricsOfTheExpectedPageAndDevice(): void
    {
        $result = LabResult::fromLighthouse(self::report(), 'https://shop.test/seeds', 'mobile');
        self::assertSame(['score' => 87, 'lcp_ms' => 2412, 'cls' => 0.051, 'tbt_ms' => 180, 'fcp_ms' => 1100, 'speed_index_ms' => 2900, 'lighthouse_version' => '12.3.0'], $result->present());
        self::assertSame(87, LabResult::fromLighthouse(self::report(['requestedUrl' => 'https://shop.test/seeds/']), 'https://shop.test/seeds', 'mobile')->present()['score'], 'A trailing slash is the same page.');
        $partial = self::report();
        unset($partial['audits']['total-blocking-time'], $partial['lighthouseVersion']);
        self::assertNull(LabResult::fromLighthouse($partial, 'https://shop.test/seeds', 'mobile')->tbtMs, 'Optional metrics may be missing.');
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function refused(): iterable
    {
        yield 'other device' => [self::report(), 'mobile', 'desktop'];
        yield 'other page' => [self::report(['requestedUrl' => 'https://shop.test/other']), 'mobile', 'mobile'];
        yield 'runtime error' => [self::report(['runtimeError' => ['code' => 'FAILED_DOCUMENT_REQUEST', 'message' => 'Lighthouse was unable to reliably load the page.']]), 'mobile', 'mobile'];
        $noLcp = self::report();
        unset($noLcp['audits']['largest-contentful-paint']);
        yield 'no LCP' => [$noLcp, 'mobile', 'mobile'];
        yield 'score out of range' => [self::report(['categories' => ['performance' => ['score' => 1.4]]]), 'mobile', 'mobile'];
    }

    /** @param array<string, mixed> $report */
    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testRefusesReportsThatDoNotMatchOrLackCoreMetrics(array $report, string $formFactor, string $device): void
    {
        $report['configSettings']['formFactor'] = $formFactor;
        $this->expectException(\InvalidArgumentException::class);
        LabResult::fromLighthouse($report, 'https://shop.test/seeds', $device);
    }

    /**
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function report(array $override = []): array
    {
        return array_replace([
            'requestedUrl' => 'https://shop.test/seeds',
            'lighthouseVersion' => '12.3.0',
            'configSettings' => ['formFactor' => 'mobile'],
            'categories' => ['performance' => ['score' => 0.87]],
            'audits' => [
                'largest-contentful-paint' => ['numericValue' => 2411.6],
                'cumulative-layout-shift' => ['numericValue' => 0.0512],
                'total-blocking-time' => ['numericValue' => 180.2],
                'first-contentful-paint' => ['numericValue' => 1100.0],
                'speed-index' => ['numericValue' => 2900.4],
            ],
        ], $override);
    }
}

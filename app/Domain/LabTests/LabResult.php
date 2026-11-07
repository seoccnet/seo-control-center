<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\LabTests;

/**
 * Lab metrics of one Lighthouse run (ADR-040, F2): the performance score
 * (0–1) and the metrics Lighthouse simulates under its fixed throttling.
 * Lab data, not what visitors experience: never merged with CrUX field data.
 */
final readonly class LabResult
{
    public function __construct(
        public float $score,
        public float $lcpMs,
        public float $cls,
        public ?float $tbtMs,
        public ?float $fcpMs,
        public ?float $siMs,
        public ?string $version,
        public ?LabDiagnostics $diagnostics = null,
    ) {}

    /**
     * Reads a Lighthouse result (PageSpeed Insights' lighthouseResult or a
     * DataForSEO Lighthouse task result) for the expected page and device.
     *
     * @param array<string, mixed> $report
     * @throws \InvalidArgumentException when the report is for another page or device, failed or lacks the core metrics
     */
    public static function fromLighthouse(array $report, string $url, string $device): self
    {
        if (isset($report['runtimeError']) && is_array($report['runtimeError']) && is_string($report['runtimeError']['code'] ?? null) && $report['runtimeError']['code'] !== 'NO_ERROR') {
            throw new \InvalidArgumentException('Lighthouse could not test the page: ' . mb_substr((string) ($report['runtimeError']['message'] ?? $report['runtimeError']['code']), 0, 200));
        }
        $formFactor = $report['configSettings']['formFactor'] ?? null;
        if ($formFactor !== $device) {
            throw new \InvalidArgumentException('Lighthouse tested another device than the profile asks for.');
        }
        $requested = is_string($report['requestedUrl'] ?? null) ? $report['requestedUrl'] : null;
        if ($requested !== null && rtrim($requested, '/') !== rtrim($url, '/')) {
            throw new \InvalidArgumentException('Lighthouse tested another page than the profile asks for.');
        }
        $score = $report['categories']['performance']['score'] ?? null;
        $metric = static function (string $audit) use ($report): ?float {
            $value = $report['audits'][$audit]['numericValue'] ?? null;

            return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
        };
        $lcp = $metric('largest-contentful-paint');
        $cls = $metric('cumulative-layout-shift');
        if (!is_numeric($score) || (float) $score < 0 || (float) $score > 1 || $lcp === null || $cls === null) {
            throw new \InvalidArgumentException('Lighthouse did not return a performance score with LCP and CLS.');
        }
        $version = $report['lighthouseVersion'] ?? null;

        // Diagnostics explain a run; an unexpected report shape never fails it.
        try {
            $diagnostics = LabDiagnostics::fromLighthouse($report);
        } catch (\Throwable) {
            $diagnostics = null;
        }

        return new self((float) $score, $lcp, $cls, $metric('total-blocking-time'), $metric('first-contentful-paint'), $metric('speed-index'), is_string($version) ? mb_substr($version, 0, 20) : null, $diagnostics);
    }

    /** Null when the two runs can be compared, otherwise why not (0.38.0). */
    public function incompatibleWith(self $other): ?string
    {
        return LabDiagnostics::incompatible($this->version, $this->diagnostics, $other->version, $other->diagnostics);
    }

    /** @return array{score: int, lcp_ms: int, cls: float, tbt_ms: ?int, fcp_ms: ?int, speed_index_ms: ?int, lighthouse_version: ?string} */
    public function present(): array
    {
        return [
            'score' => (int) round($this->score * 100),
            'lcp_ms' => (int) round($this->lcpMs),
            'cls' => round($this->cls, 3),
            'tbt_ms' => $this->tbtMs !== null ? (int) round($this->tbtMs) : null,
            'fcp_ms' => $this->fcpMs !== null ? (int) round($this->fcpMs) : null,
            'speed_index_ms' => $this->siMs !== null ? (int) round($this->siMs) : null,
            'lighthouse_version' => $this->version,
        ];
    }
}

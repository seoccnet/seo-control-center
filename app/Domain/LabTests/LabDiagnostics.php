<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\LabTests;

/**
 * Why a Lighthouse run scored as it did (ADR-042, 0.38.0), read from the
 * report the run already received: the LCP element and its phases,
 * render-blocking resources, the top diagnostics, a resource summary, the
 * test configuration and a reference (hash and size) to the raw report.
 * Provider text is data, never instructions; strings are cleaned and bounded.
 *
 * @phpstan-type Config array{form_factor: string, screen: ?string, throttling_method: ?string, rtt_ms: ?float, throughput_kbps: ?float, cpu_slowdown: ?float, benchmark_index: ?float, user_agent: ?string, fetch_time: ?string, final_url: ?string, channel: ?string}
 * @phpstan-type Lcp array{element: ?string, snippet: ?string, resource: ?string, phases: list<array{phase: string, ms: int}>, checks: list<array{label: string, passed: bool}>}
 * @phpstan-type Blocking array{url: string, wasted_ms: ?int, bytes: ?int}
 * @phpstan-type Diagnostic array{id: string, title: string, display: ?string, score: ?float, savings: array<string, int>}
 * @phpstan-type ResourceType array{type: string, requests: int, bytes: int}
 * @phpstan-type Request array{url: string, type: string, bytes: int, ms: ?int}
 * @phpstan-type Report array{sha256: string, bytes: int}
 */
final readonly class LabDiagnostics
{
    private const int BLOCKING = 10;
    private const int DIAGNOSTICS = 8;
    private const int LARGEST = 5;
    /** Diagnostics count only when they fall short of a good score. */
    private const float GOOD = 0.9;
    /** Lighthouse 13 insights that describe the LCP; shown in the LCP section, not as diagnostics (0.38.1). */
    private const array LCP_INSIGHTS = ['lcp-breakdown-insight', 'lcp-discovery-insight'];

    /**
     * @param Config $config
     * @param Lcp|null $lcp
     * @param list<Blocking> $blocking
     * @param list<Diagnostic> $diagnostics
     * @param list<string> $also titles of audits below a good score without an estimated saving or value (0.38.1)
     * @param list<ResourceType> $resources
     * @param list<Request> $largest
     * @param Report $report
     */
    public function __construct(
        public array $config,
        public ?array $lcp,
        public array $blocking,
        public array $diagnostics,
        public array $also,
        public array $resources,
        public array $largest,
        public array $report,
    ) {}

    /** @param array<string, mixed> $report a Lighthouse result (PageSpeed Insights' lighthouseResult or a DataForSEO Lighthouse result) */
    public static function fromLighthouse(array $report): self
    {
        $audits = is_array($report['audits'] ?? null) ? $report['audits'] : [];
        $raw = json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
        $finalUrl = self::url($report['finalDisplayedUrl'] ?? $report['finalUrl'] ?? null);
        [$resources, $largest] = self::network($audits);
        [$diagnostics, $also] = self::diagnostics($report, $audits);

        return new self(
            self::config($report, $finalUrl),
            self::lcp($audits, $finalUrl),
            self::blocking($audits),
            $diagnostics,
            $also,
            $resources,
            $largest,
            ['sha256' => hash('sha256', $raw), 'bytes' => strlen($raw)],
        );
    }

    /**
     * Whether two runs' results can be compared: the same Lighthouse major
     * version and, when both are known, the same throttling and screen.
     * Null when comparable, otherwise the reason.
     */
    public static function incompatible(?string $versionA, ?self $a, ?string $versionB, ?self $b): ?string
    {
        $major = static fn(?string $v): ?string => $v === null ? null : explode('.', $v)[0];
        if ($major($versionA) !== null && $major($versionB) !== null && $major($versionA) !== $major($versionB)) {
            return sprintf('Lighthouse %s and %s score differently', $versionA, $versionB);
        }
        if ($a !== null && $b !== null) {
            foreach (['throttling_method' => 'throttling', 'rtt_ms' => 'throttling', 'throughput_kbps' => 'throttling', 'cpu_slowdown' => 'CPU slowdown', 'screen' => 'screen size'] as $key => $label) {
                if ($a->config[$key] !== null && $b->config[$key] !== null && $a->config[$key] !== $b->config[$key]) {
                    return 'different ' . $label;
                }
            }
        }

        return null;
    }

    /** @return array{config: Config, lcp: Lcp|null, render_blocking: list<Blocking>, diagnostics: list<Diagnostic>, also_flagged: list<string>, resources: list<ResourceType>, largest_requests: list<Request>, report: Report} */
    public function present(): array
    {
        return ['config' => $this->config, 'lcp' => $this->lcp, 'render_blocking' => $this->blocking, 'diagnostics' => $this->diagnostics, 'also_flagged' => $this->also, 'resources' => $this->resources, 'largest_requests' => $this->largest, 'report' => $this->report];
    }

    /** Reads a stored present() array; null when it is not one. */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_array($data['config'] ?? null) || !is_array($data['report'] ?? null) || !is_string($data['report']['sha256'] ?? null)) {
            return null;
        }
        $c = $data['config'];
        $config = ['form_factor' => self::text($c['form_factor'] ?? null, 10) ?? '', 'screen' => self::text($c['screen'] ?? null, 40), 'throttling_method' => self::text($c['throttling_method'] ?? null, 20), 'rtt_ms' => self::number($c['rtt_ms'] ?? null), 'throughput_kbps' => self::number($c['throughput_kbps'] ?? null), 'cpu_slowdown' => self::number($c['cpu_slowdown'] ?? null), 'benchmark_index' => self::number($c['benchmark_index'] ?? null), 'user_agent' => self::text($c['user_agent'] ?? null, 300), 'fetch_time' => self::text($c['fetch_time'] ?? null, 19), 'final_url' => self::url($c['final_url'] ?? null), 'channel' => self::text($c['channel'] ?? null, 40)];
        $lcp = null;
        if (is_array($data['lcp'] ?? null)) {
            $phases = [];
            foreach (is_array($data['lcp']['phases'] ?? null) ? $data['lcp']['phases'] : [] as $p) {
                if (is_array($p) && is_string($p['phase'] ?? null) && is_int($p['ms'] ?? null)) {
                    $phases[] = ['phase' => self::text($p['phase'], 40) ?? '', 'ms' => $p['ms']];
                }
            }
            $checks = [];
            foreach (is_array($data['lcp']['checks'] ?? null) ? $data['lcp']['checks'] : [] as $check) {
                if (is_array($check) && is_string($check['label'] ?? null) && is_bool($check['passed'] ?? null)) {
                    $checks[] = ['label' => self::text($check['label'], 120) ?? '', 'passed' => $check['passed']];
                }
            }
            $lcp = ['element' => self::text($data['lcp']['element'] ?? null, 200), 'snippet' => self::text($data['lcp']['snippet'] ?? null, 300), 'resource' => self::url($data['lcp']['resource'] ?? null), 'phases' => $phases, 'checks' => $checks];
        }
        $blocking = [];
        foreach (is_array($data['render_blocking'] ?? null) ? $data['render_blocking'] : [] as $b) {
            if (is_array($b) && ($url = self::url($b['url'] ?? null)) !== null) {
                $blocking[] = ['url' => $url, 'wasted_ms' => is_int($b['wasted_ms'] ?? null) ? $b['wasted_ms'] : null, 'bytes' => is_int($b['bytes'] ?? null) ? $b['bytes'] : null];
            }
        }
        $diagnostics = [];
        foreach (is_array($data['diagnostics'] ?? null) ? $data['diagnostics'] : [] as $d) {
            if (is_array($d) && is_string($d['id'] ?? null) && is_string($d['title'] ?? null)) {
                $savings = [];
                foreach (is_array($d['savings'] ?? null) ? $d['savings'] : [] as $metric => $ms) {
                    if (is_string($metric) && is_int($ms)) {
                        $savings[$metric] = $ms;
                    }
                }
                $diagnostics[] = ['id' => self::text($d['id'], 80) ?? '', 'title' => self::text($d['title'], 200) ?? '', 'display' => self::text($d['display'] ?? null, 100), 'score' => self::number($d['score'] ?? null), 'savings' => $savings];
            }
        }
        $also = [];
        foreach (is_array($data['also_flagged'] ?? null) ? $data['also_flagged'] : [] as $title) {
            if (($title = self::text($title, 200)) !== null) {
                $also[] = $title;
            }
        }
        $resources = [];
        foreach (is_array($data['resources'] ?? null) ? $data['resources'] : [] as $r) {
            if (is_array($r) && is_string($r['type'] ?? null) && is_int($r['requests'] ?? null) && is_int($r['bytes'] ?? null)) {
                $resources[] = ['type' => self::text($r['type'], 30) ?? '', 'requests' => $r['requests'], 'bytes' => $r['bytes']];
            }
        }
        $largest = [];
        foreach (is_array($data['largest_requests'] ?? null) ? $data['largest_requests'] : [] as $r) {
            if (is_array($r) && ($url = self::url($r['url'] ?? null)) !== null && is_int($r['bytes'] ?? null)) {
                $largest[] = ['url' => $url, 'type' => self::text($r['type'] ?? null, 30) ?? 'Other', 'bytes' => $r['bytes'], 'ms' => is_int($r['ms'] ?? null) ? $r['ms'] : null];
            }
        }

        return new self($config, $lcp, $blocking, $diagnostics, $also, $resources, $largest, ['sha256' => substr($data['report']['sha256'], 0, 64), 'bytes' => is_int($data['report']['bytes'] ?? null) ? $data['report']['bytes'] : 0]);
    }

    /**
     * @param array<string, mixed> $report
     * @return Config
     */
    private static function config(array $report, ?string $finalUrl): array
    {
        $settings = is_array($report['configSettings'] ?? null) ? $report['configSettings'] : [];
        $throttling = is_array($settings['throttling'] ?? null) ? $settings['throttling'] : [];
        $screen = is_array($settings['screenEmulation'] ?? null) ? $settings['screenEmulation'] : [];
        $environment = is_array($report['environment'] ?? null) ? $report['environment'] : [];
        $width = self::number($screen['width'] ?? null);
        $height = self::number($screen['height'] ?? null);
        $scale = self::number($screen['deviceScaleFactor'] ?? null);
        $fetch = null;
        if (is_string($report['fetchTime'] ?? null)) {
            try {
                $fetch = (new \DateTimeImmutable($report['fetchTime']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                $fetch = null;
            }
        }

        return [
            'form_factor' => self::text($settings['formFactor'] ?? null, 10) ?? '',
            'screen' => $width !== null && $height !== null && ($screen['disabled'] ?? false) !== true ? sprintf('%d×%d%s', $width, $height, $scale !== null ? ' @' . rtrim(rtrim(number_format($scale, 2, '.', ''), '0'), '.') . 'x' : '') : null,
            'throttling_method' => self::text($settings['throttlingMethod'] ?? null, 20),
            'rtt_ms' => self::number($throttling['rttMs'] ?? null),
            'throughput_kbps' => self::number($throttling['throughputKbps'] ?? null),
            'cpu_slowdown' => self::number($throttling['cpuSlowdownMultiplier'] ?? null),
            'benchmark_index' => self::number($environment['benchmarkIndex'] ?? null),
            'user_agent' => self::text($environment['networkUserAgent'] ?? $settings['emulatedUserAgent'] ?? null, 300),
            'fetch_time' => $fetch,
            'final_url' => $finalUrl,
            'channel' => self::text($settings['channel'] ?? null, 40),
        ];
    }

    /**
     * @param array<mixed> $audits
     * @return Lcp|null
     */
    private static function lcp(array $audits, ?string $finalUrl): ?array
    {
        // Lighthouse 10–12 have the element audit; Lighthouse 13 replaced it with the LCP breakdown and discovery insights.
        $sources = [];
        foreach (['largest-contentful-paint-element', 'lcp-breakdown-insight', 'lcp-discovery-insight'] as $id) {
            if (is_array($audits[$id]['details'] ?? null)) {
                $sources[$id] = $audits[$id]['details'];
            }
        }
        $node = null;
        $found = [];
        $phases = [];
        $checks = [];
        foreach ($sources as $id => $details) {
            // A list nests tables, a checklist and the element itself; older versions have only the element table.
            $parts = ($details['type'] ?? null) === 'list' && is_array($details['items'] ?? null) ? $details['items'] : [$details];
            foreach ($parts as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? null) === 'node') {
                    $node ??= $part;
                }
                if (($part['type'] ?? null) === 'checklist' && is_array($part['items'] ?? null)) {
                    foreach ($part['items'] as $check) {
                        if (is_array($check) && is_string($check['label'] ?? null) && is_bool($check['value'] ?? null)) {
                            $checks[] = ['label' => self::text(strip_tags($check['label']), 120) ?? '', 'passed' => $check['value']];
                        }
                    }
                }
                foreach (is_array($part['items'] ?? null) && ($part['type'] ?? null) !== 'checklist' ? $part['items'] : [] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (is_array($item['node'] ?? null)) {
                        $node ??= $item['node'];
                    }
                    // Phases: "phase"/"timing" up to Lighthouse 12, "label"/"duration" in Lighthouse 13.
                    if (is_string($item['phase'] ?? null) && is_numeric($item['timing'] ?? null)) {
                        $found[$id][] = ['phase' => self::text($item['phase'], 40) ?? '', 'ms' => (int) round((float) $item['timing'])];
                    } elseif (is_string($item['subpart'] ?? null) && is_string($item['label'] ?? null) && is_numeric($item['duration'] ?? null)) {
                        $found[$id][] = ['phase' => self::text($item['label'], 40) ?? '', 'ms' => (int) round((float) $item['duration'])];
                    }
                }
            }
        }
        // The first source with phases wins.
        foreach ($found as $list) {
            $phases = $list;
            break;
        }
        if ($node === null) {
            return null;
        }
        $snippet = self::text($node['snippet'] ?? null, 300);
        $resource = null;
        // The image's own address, resolved against the tested page; a text element has none.
        if ($snippet !== null && preg_match('/\ssrc="([^"]+)"/i', $snippet, $m) === 1) {
            $src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            $resource = self::url(str_starts_with($src, '//') ? 'https:' . $src : (str_starts_with($src, '/') && $finalUrl !== null ? preg_replace('#^(https?://[^/]+).*$#', '$1', $finalUrl) . $src : $src));
        }

        return ['element' => self::text($node['nodeLabel'] ?? $node['selector'] ?? null, 200), 'snippet' => $snippet, 'resource' => $resource, 'phases' => $phases, 'checks' => $checks];
    }

    /**
     * @param array<mixed> $audits
     * @return list<Blocking>
     */
    private static function blocking(array $audits): array
    {
        $out = [];
        foreach (['render-blocking-resources', 'render-blocking-insight'] as $id) {
            foreach (is_array($audits[$id]['details']['items'] ?? null) ? $audits[$id]['details']['items'] : [] as $item) {
                if (is_array($item) && ($url = self::url($item['url'] ?? null)) !== null && !isset($out[$url])) {
                    $out[$url] = ['url' => $url, 'wasted_ms' => is_numeric($item['wastedMs'] ?? null) ? (int) round((float) $item['wastedMs']) : null, 'bytes' => is_numeric($item['totalBytes'] ?? null) ? (int) $item['totalBytes'] : null];
                }
            }
        }
        $out = array_values($out);
        usort($out, static fn(array $a, array $b): int => [$b['wasted_ms'] ?? -1, $a['url']] <=> [$a['wasted_ms'] ?? -1, $b['url']]);

        return array_slice($out, 0, self::BLOCKING);
    }

    /**
     * @param array<string, mixed> $report
     * @param array<mixed> $audits
     * @return array{list<Diagnostic>, list<string>}
     */
    private static function diagnostics(array $report, array $audits): array
    {
        $out = [];
        $also = [];
        foreach (is_array($report['categories']['performance']['auditRefs'] ?? null) ? $report['categories']['performance']['auditRefs'] : [] as $ref) {
            if (!is_array($ref) || !is_string($ref['id'] ?? null) || in_array($ref['group'] ?? null, ['metrics', 'hidden'], true) || in_array($ref['id'], self::LCP_INSIGHTS, true)) {
                continue;
            }
            $audit = $audits[$ref['id']] ?? null;
            if (!is_array($audit) || !is_numeric($audit['score'] ?? null) || (float) $audit['score'] >= self::GOOD || !in_array($audit['scoreDisplayMode'] ?? null, ['metricSavings', 'numeric', 'binary'], true) || !is_string($audit['title'] ?? null)) {
                continue;
            }
            $savings = [];
            foreach (is_array($audit['metricSavings'] ?? null) ? $audit['metricSavings'] : [] as $metric => $value) {
                if (is_string($metric) && is_numeric($value) && (float) $value > 0) {
                    $savings[$metric] = (int) round((float) $value * ($metric === 'CLS' ? 1000 : 1));
                }
            }
            $display = self::text($audit['displayValue'] ?? null, 100);
            $title = self::text(strip_tags($audit['title']), 200) ?? '';
            // Without an estimated saving or a value it is context ("Network dependency tree"), not a shortfall to rank.
            if ($savings === [] && $display === null) {
                $also[] = $title;
                continue;
            }
            $out[] = ['id' => self::text($ref['id'], 80) ?? '', 'title' => $title, 'display' => $display, 'score' => round((float) $audit['score'], 2), 'savings' => $savings];
        }
        // Largest time saving first (CLS counts in thousandths), then the lowest score.
        $weight = static fn(array $d): int => ($d['savings']['LCP'] ?? 0) + ($d['savings']['FCP'] ?? 0) + ($d['savings']['TBT'] ?? 0) + ($d['savings']['CLS'] ?? 0);
        usort($out, static fn(array $a, array $b): int => [$weight($b), $a['score'], $a['id']] <=> [$weight($a), $b['score'], $b['id']]);

        return [array_slice($out, 0, self::DIAGNOSTICS), array_slice($also, 0, self::DIAGNOSTICS)];
    }

    /**
     * @param array<mixed> $audits
     * @return array{list<ResourceType>, list<Request>}
     */
    private static function network(array $audits): array
    {
        $requests = [];
        foreach (is_array($audits['network-requests']['details']['items'] ?? null) ? $audits['network-requests']['details']['items'] : [] as $item) {
            if (!is_array($item) || ($url = self::url($item['url'] ?? null)) === null) {
                continue;
            }
            $start = $item['networkRequestTime'] ?? $item['startTime'] ?? null;
            $end = $item['networkEndTime'] ?? $item['endTime'] ?? null;
            $requests[] = ['url' => $url, 'type' => self::text($item['resourceType'] ?? null, 30) ?? 'Other', 'bytes' => is_numeric($item['transferSize'] ?? null) ? max(0, (int) $item['transferSize']) : 0, 'ms' => is_numeric($start) && is_numeric($end) && (float) $end >= (float) $start ? (int) round((float) $end - (float) $start) : null];
        }
        $resources = [];
        $summary = $audits['resource-summary']['details']['items'] ?? null;
        if (is_array($summary) && $summary !== []) {
            foreach ($summary as $item) {
                if (is_array($item) && is_string($item['resourceType'] ?? null) && is_numeric($item['requestCount'] ?? null)) {
                    $resources[] = ['type' => self::text($item['label'] ?? $item['resourceType'], 30) ?? '', 'requests' => (int) $item['requestCount'], 'bytes' => is_numeric($item['transferSize'] ?? null) ? (int) $item['transferSize'] : 0];
                }
            }
        } else {
            // Newer Lighthouse versions dropped the summary; count the network requests instead.
            $by = [];
            foreach ($requests as $r) {
                $by[$r['type']] ??= ['type' => $r['type'], 'requests' => 0, 'bytes' => 0];
                $by[$r['type']]['requests']++;
                $by[$r['type']]['bytes'] += $r['bytes'];
            }
            if ($by !== []) {
                $resources[] = ['type' => 'Total', 'requests' => count($requests), 'bytes' => array_sum(array_column($requests, 'bytes'))];
                $by = array_values($by);
                usort($by, static fn(array $a, array $b): int => [$b['bytes'], $a['type']] <=> [$a['bytes'], $b['type']]);
                $resources = [...$resources, ...$by];
            }
        }
        usort($requests, static fn(array $a, array $b): int => [$b['bytes'], $a['url']] <=> [$a['bytes'], $b['url']]);

        return [$resources, array_slice($requests, 0, self::LARGEST)];
    }

    private static function text(mixed $value, int $limit): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $clean = trim(mb_substr(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $value) ?? '', 0, $limit));

        return $clean === '' ? null : $clean;
    }

    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (is_finite((float) $value) ? (float) $value : null) : null;
    }

    private static function url(mixed $value): ?string
    {
        $url = self::text($value, 2048);

        return $url !== null && preg_match('#^https?://[^\s]+$#i', $url) === 1 ? $url : null;
    }
}

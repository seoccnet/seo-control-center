<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\WebVitals\WebVitalRecord;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\WebVitals\WebVitalsRepository;

/**
 * Saved Chrome UX Report field data (Core Web Vitals): the latest snapshot
 * per checked URL or origin, and with `url` that page's weekly history and
 * watch state. Reads stored snapshots; it never calls the CrUX API.
 */
final readonly class WebVitalsTool implements McpTool
{
    private const array GOOD = ['lcp_ms' => 2500, 'inp_ms' => 200, 'cls' => 0.1];

    public function __construct(private WebVitalsRepository $vitals) {}

    public function name(): string
    {
        return 'web_vitals';
    }

    public function title(): string
    {
        return 'Core Web Vitals';
    }

    public function scope(): McpScope
    {
        return McpScope::Pages;
    }

    public function description(): string
    {
        return 'Saved Core Web Vitals field data from the Chrome UX Report (real Chrome users, 75th percentile over 28 days): LCP and INP in milliseconds and CLS, per checked URL or origin, for mobile (PHONE) or DESKTOP, with which metrics miss Google\'s "good" thresholds (LCP 2,500 ms, INP 200 ms, CLS 0.1). With `url`, that page\'s weekly history (up to 52 snapshots) and whether it is watched. These are field measurements, separate from lab (Lighthouse) values. Reads stored snapshots; no CrUX request.';
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'form_factor' => ['type' => 'string', 'enum' => ['PHONE', 'DESKTOP'], 'default' => 'PHONE'],
            'url' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'Exact checked URL or origin for its history.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $device = ToolContext::choice($arguments, 'form_factor', 'PHONE', ['PHONE', 'DESKTOP']);
        $url = ReadInput::url($arguments);
        $base = [...Present::project($project), 'form_factor' => $device, 'thresholds' => self::GOOD, 'watched_pages' => $this->vitals->watchCount($project->id)];
        $notice = 'Field data covers real Chrome users over 28 days (75th percentile) and lags site changes by up to four weeks. "no_data" means Chrome has too little traffic for that URL, not that it is fast or slow.';
        if ($url !== null) {
            $history = $this->vitals->history($project->id, $url, $device);
            $latest = $this->vitals->find($project->id, $url, $device);

            return [...$base, 'url' => $url, 'watched' => $this->vitals->watching($project->id, $url, $device), 'latest' => $latest !== null ? self::record($latest) : null, 'history' => array_map(self::record(...), $history), 'notice' => $notice];
        }
        $limit = ToolContext::int($arguments, 'limit', 25, 1, 100);
        $offset = ToolContext::int($arguments, 'offset', 0, 0, 100000);
        $all = $this->vitals->list($project->id, 2000, $device);

        return [...$base, 'total' => count($all), 'offset' => $offset, 'next_offset' => $offset + $limit < count($all) ? $offset + $limit : null,
            'needing_work' => count(array_filter($all, static fn(WebVitalRecord $r): bool => $r->needsWork() !== [])),
            'pages' => array_map(self::record(...), array_slice($all, $offset, $limit)), 'notice' => $notice];
    }

    /** @return array<string, mixed> */
    private static function record(WebVitalRecord $record): array
    {
        return [...$record->toArray(), 'needs_work' => $record->needsWork()];
    }
}

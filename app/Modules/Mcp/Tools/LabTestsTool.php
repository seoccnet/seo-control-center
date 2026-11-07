<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\LabTests\LabRun;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\LabTests\LabTestService;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\Paging;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

/**
 * lab_tests (ADR-040, F2): saved speed test profiles with their latest lab
 * runs and the CrUX field data of the same page and device, kept apart.
 */
final readonly class LabTestsTool implements McpTool
{
    public function __construct(private LabTestService $tests) {}

    public function name(): string
    {
        return 'lab_tests';
    }

    public function title(): string
    {
        return 'Speed tests';
    }

    public function description(): string
    {
        return 'Saved speed test profiles (page, device mobile/desktop, source pagespeed = free PageSpeed Insights or dataforseo = paid Lighthouse, weekly yes/no) with their latest 8 lab runs, newest first: Lighthouse performance score 0-100, LCP, CLS, TBT, FCP and Speed Index in milliseconds (CLS unitless), or the error of a failed run. field is the Chrome UX Report record of the same page and device (75th percentile of real Chrome users), separate from lab runs. Compare runs within one profile only, and only when comparable_with_previous is true (a run marked false used another Lighthouse major version, throttling or screen than the run before; the reason is given). diagnostics explains the latest finished run: LCP element, phases and request-discovery checks, render-blocking resources, top diagnostics below a good score with the savings Lighthouse estimates, also_flagged (titles without a saving or value), requests by type, the largest requests, the test configuration, and a SHA-256 and size of the raw report (the report itself is not stored). Runs before 0.38.0 have no diagnostics. Lab and field measure different things. Read-only: runs are started in the app. Paged with offset and limit (10 by default, at most 50); total counts all profiles and next_offset is null on the last page.';
    }

    public function scope(): McpScope
    {
        return McpScope::Pages;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema(Paging::schema(10, 50));
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $data = $this->tests->overview($context->access->user, $project);
        $page = Paging::slice(array_values($data['profiles']), $arguments, 10, 50);

        return [
            'project_id' => $project->publicId,
            'offset' => $page['offset'], 'limit' => $page['limit'], 'total' => $page['total'], 'next_offset' => $page['next_offset'],
            'profiles' => array_map(static fn(array $row): array => [
                ...$row['profile']->present(),
                'runs' => self::runs($row['runs']),
                'diagnostics' => (array_values(array_filter($row['runs'], static fn(LabRun $r): bool => $r->result !== null))[0] ?? null)?->result?->diagnostics?->present(),
                'field' => $row['field']?->toArray(),
            ], $page['rows']),
            'notice' => 'Lab results come from Lighthouse under the source\'s fixed simulated conditions; field data comes from real Chrome users. They are never averaged together.',
        ];
    }

    /**
     * @param list<LabRun> $runs newest first
     * @return list<array<string, mixed>>
     */
    private static function runs(array $runs): array
    {
        $done = array_values(array_filter($runs, static fn(LabRun $r): bool => $r->result !== null));
        $out = [];
        foreach ($runs as $run) {
            $row = $run->present();
            $index = array_search($run, $done, true);
            if ($run->result !== null && is_int($index) && isset($done[$index + 1]) && $done[$index + 1]->result !== null) {
                $why = $run->result->incompatibleWith($done[$index + 1]->result);
                $row['comparable_with_previous'] = $why === null;
                $row['not_comparable_because'] = $why;
            }
            $out[] = $row;
        }

        return $out;
    }
}

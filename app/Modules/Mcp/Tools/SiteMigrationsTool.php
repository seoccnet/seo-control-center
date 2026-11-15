<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\SiteMigration\RedirectCheck;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\SiteMigration\MigrationUrl;
use SeoControlCenter\Modules\SiteMigration\SiteMigration;
use SeoControlCenter\Modules\SiteMigration\SiteMigrationService;

/**
 * site_migrations (1.2.1): the project's site migration plans, and for one
 * plan its redirects with their last test and the post-launch clicks.
 * Read-only: never suggests, tests or changes redirects.
 */
final readonly class SiteMigrationsTool implements McpTool
{
    public const int PER_PAGE = 25;

    public function __construct(private SiteMigrationService $migrations) {}

    public function name(): string
    {
        return 'site_migrations';
    }

    public function title(): string
    {
        return 'Site migrations';
    }

    public function description(): string
    {
        return 'Site migration plans (domain moves and URL restructures) with their redirects. Without plan_id: every plan with its old and new site, launch date, address counts and the state of redirect suggestions and tests. With plan_id: test_counts per result, and the old addresses with their 28-day Search Console clicks, suggested and chosen target, match method and confidence, and last redirect test (25 per page with offset/next_offset, most clicks first). filter: problems (temporary, wrong_target, chain, target_broken, not_redirected, missing), unmatched (no target), or one result. Test results: ok, temporary, wrong_target, chain, target_broken, not_redirected (still answers itself; normal before launch), missing (404/410), failed, skipped, untested. After launch, results compares each old address\'s clicks in the 28 days before launch with its new address since launch (scaled to 28 days); waiting says why results are not ready. Tests read server responses at the time of the test; a later change on the site is not reflected until the next test.';
    }

    public function scope(): McpScope
    {
        return McpScope::Pages;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'plan_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'A plan id from the list, for its redirects.'],
            'filter' => ['type' => 'string', 'enum' => ['problems', 'unmatched', ...RedirectCheck::PROBLEMS, RedirectCheck::OK, RedirectCheck::FAILED, RedirectCheck::SKIPPED, RedirectCheck::UNTESTED]],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $planId = $arguments['plan_id'] ?? null;
        if ($planId === null || $planId === '') {
            return ['project_id' => $project->publicId, 'plans' => array_map(self::plan(...), $this->migrations->list($project))];
        }
        if (!is_string($planId)) {
            throw new ToolError('plan_id must be text.');
        }
        try {
            $plan = $this->migrations->get($project, $planId);
        } catch (NotFoundHttpException) {
            throw new ToolError('Unknown plan_id. Call site_migrations without plan_id for the list.');
        }
        $filter = is_string($arguments['filter'] ?? null) ? $arguments['filter'] : null;
        $offset = is_int($arguments['offset'] ?? null) ? max(0, min(100000, $arguments['offset'])) : 0;
        $page = intdiv($offset, self::PER_PAGE) + 1;
        $rows = $this->migrations->rows($plan, $filter, $page, self::PER_PAGE);
        $start = ($page - 1) * self::PER_PAGE;
        $results = $this->migrations->results($project, $plan);

        return [
            'project_id' => $project->publicId,
            'plan' => self::plan($plan),
            'test_counts' => $rows['counts'],
            'filter' => $filter,
            'total' => $rows['total'],
            'offset' => $start,
            'next_offset' => $start + self::PER_PAGE < $rows['total'] ? $start + self::PER_PAGE : null,
            'redirects' => array_map(self::row(...), $rows['rows']),
            'results' => $results === null ? null : [
                'waiting' => $results['waiting'],
                'before' => ['from' => $results['before']->fromString(), 'to' => $results['before']->toString(), 'clicks' => $results['beforeClicks']],
                'after' => $results['waiting'] !== null ? null : ['from' => $results['after']->fromString(), 'to' => $results['after']->toString(), 'clicks_per_28_days' => $results['afterClicks']],
                'largest_changes' => array_map(static fn(array $r): array => ['old_url' => $r['url']->oldUrl, 'target_url' => $r['url']->targetUrl, 'clicks_before' => $r['before'], 'clicks_after_per_28_days' => $r['after']], array_slice($results['rows'], 0, 15)),
            ],
            'notice' => 'Plans are managed in the app under Improve → Site migration. Before launch most old addresses still answer themselves (not_redirected). Click comparisons do not prove why traffic changed.',
        ];
    }

    /** @return array<string, mixed> */
    private static function plan(SiteMigration $plan): array
    {
        return [
            'id' => $plan->publicId,
            'name' => $plan->name,
            'old_origin' => $plan->oldOrigin,
            'new_origin' => $plan->newOrigin,
            'launch_date' => $plan->launchDate,
            'old_addresses' => $plan->urlCount,
            'new_addresses' => $plan->targetCount,
            'suggestions' => $plan->matchState,
            'redirect_test' => $plan->testState,
            'tested_at' => $plan->testedAt,
            'error' => $plan->error,
        ];
    }

    /** @return array<string, mixed> */
    private static function row(MigrationUrl $row): array
    {
        return [
            'old_url' => $row->oldUrl,
            'clicks_28d' => $row->oldClicks,
            'target_url' => $row->targetUrl,
            'suggested_url' => $row->suggestedUrl,
            'match' => $row->manual ? 'manual' : $row->method,
            'confidence' => $row->manual ? null : $row->confidence,
            'test' => $row->testState,
            'test_detail' => $row->testDetail,
            'tested_at' => $row->testedAt,
        ];
    }
}

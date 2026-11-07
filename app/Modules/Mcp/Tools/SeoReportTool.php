<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Reports\ReportBuilder;
use SeoControlCenter\Modules\Reports\ReportPeriod;

/**
 * The weekly or monthly project report as data: the same figures as the
 * printable report and its PDF, compared with the period before. Each
 * section also needs its own scope; sections outside the token are null.
 */
final readonly class SeoReportTool implements McpTool
{
    public function __construct(private ReportBuilder $builder, private Clock $clock) {}

    public function name(): string
    {
        return 'seo_report';
    }

    public function title(): string
    {
        return 'SEO report';
    }

    public function scope(): McpScope
    {
        return McpScope::Projects;
    }

    public function description(): string
    {
        return 'The weekly or monthly SEO report of a project as data, the same figures as the printable report and its PDF: Google + Bing search totals against the previous period, daily clicks, top pages and queries, rankings with winners and losers, GA4 organic conversions, the latest site and browser audit, and the top open opportunities. Defaults to the latest complete week or month; `to` picks an earlier one. Sections need their own scopes (search:read, rankings:read, pages:read, opportunities:read) and are null without them. Stored data only.';
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'frequency' => ['type' => 'string', 'enum' => ['weekly', 'monthly'], 'default' => 'weekly'],
            'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Last day of a complete week (Sunday) or month; defaults to the latest complete one.'],
            'include_daily' => ['type' => 'boolean', 'default' => false, 'description' => 'Include clicks per day.'],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $token = $context->access->token;
        $frequency = ToolContext::choice($arguments, 'frequency', 'weekly', ['weekly', 'monthly']);
        $latest = ReportPeriod::latestComplete($frequency, $this->clock->now(), $project->timezone);
        $period = $latest;
        if (isset($arguments['to'])) {
            $requested = is_string($arguments['to']) ? ReportPeriod::endingOn($frequency, $arguments['to']) : null;
            if ($requested === null) {
                throw new ToolError($frequency === 'weekly' ? 'to must be a Sunday (YYYY-MM-DD) ending a week.' : 'to must be the last day of a month (YYYY-MM-DD).');
            }
            if ($requested->toString() > $latest->toString()) {
                throw new ToolError('That period has not ended yet. The latest complete one ends ' . $latest->toString() . '.');
            }
            $period = $requested;
        }
        $report = $this->builder->build($project, $frequency, $period);
        $search = $report['search'];
        $rankings = $report['rankings'];
        $moves = static fn(array $list): array => array_map(static fn(RankingMove $m): array => ['keyword' => $m->keyword, 'from' => $m->from, 'to' => $m->to, 'change' => $m->change()], $list);

        return [
            ...Present::project($project),
            'frequency' => $frequency,
            'label' => $report['label'],
            'period' => ['from' => $period->fromString(), 'to' => $period->toString()],
            'previous_period' => ['from' => $report['previousPeriod']->fromString(), 'to' => $report['previousPeriod']->toString()],
            'latest_complete_to' => $latest->toString(),
            'search' => !$token->allows(McpScope::Search) || $search === null ? null : [
                'engines' => array_keys(array_filter(['google' => $search['google'] !== null, 'bing' => $search['bing'] !== null])),
                'combined' => Present::metrics($search['combined']),
                'combined_previous' => Present::metrics($search['combinedPrevious']),
                'google' => $search['google'] !== null ? Present::metrics($search['google']) : null,
                'google_previous' => $search['googlePrevious'] !== null ? Present::metrics($search['googlePrevious']) : null,
                'bing' => $search['bing'] !== null ? Present::metrics($search['bing']) : null,
                'bing_previous' => $search['bingPrevious'] !== null ? Present::metrics($search['bingPrevious']) : null,
                'position_note' => 'Combined clicks and impressions add Google and Bing; the position is Google\'s.',
                'top_pages' => array_map(static fn(DimensionRow $r): array => Present::row($r), $report['pages']),
                'top_queries' => array_map(static fn(DimensionRow $r): array => Present::row($r), $report['queries']),
                'daily_clicks' => ($arguments['include_daily'] ?? false) === true ? array_map(static fn(array $p): array => ['date' => $p[0], 'clicks' => $p[1]], $report['clicks']) : null,
            ],
            'rankings' => !$token->allows(McpScope::Rankings) || $rankings === null ? null : [
                'device' => $rankings['device'],
                'check' => Present::day($rankings['day']),
                'previous_check' => Present::day($rankings['previous']),
                'winners' => $moves($rankings['winners']),
                'losers' => $moves($rankings['losers']),
            ],
            'analytics' => $token->allows(McpScope::Pages) ? $report['analytics'] : null,
            'site_audit' => $token->allows(McpScope::Opportunities) ? $report['siteAudit'] : null,
            'browser_audit' => $token->allows(McpScope::Opportunities) ? $report['renderedAudit'] : null,
            'opportunities' => !$token->allows(McpScope::Opportunities) ? null : [
                'open' => $report['openOpportunities'],
                'top' => array_map(static fn(StoredOpportunity $o): array => ['opportunity_id' => $o->publicId, 'title' => $o->title, 'type' => $o->type->value, 'priority' => $o->priority, 'impact' => $o->impact, 'effort' => $o->effort], $report['opportunities']),
            ],
            'notice' => 'Stored data only; nothing is imported or recalculated. Search Console and Bing report the last days of a period with a short delay. Null is unavailable or outside the token scopes, not zero. Changes between periods are observations, not causes.',
        ];
    }
}

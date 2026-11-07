<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Alerts\Alert;
use SeoControlCenter\Modules\Alerts\AlertRepository;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Reports\ReportScheduleRepository;

/** Curated business monitoring, never recipient addresses, hooks or secrets. */
final readonly class ProjectMonitoringTool implements McpTool
{
    public function __construct(private AlertRepository $alerts, private ReportScheduleRepository $reports, private UsageLedger $usage, private Authorizer $auth, private Clock $clock, private ?\SeoControlCenter\Modules\IndexNow\IndexNowRepository $indexNow = null) {}
    public function name(): string
    {
        return 'project_monitoring';
    }
    public function title(): string
    {
        return 'Alerts, reports, IndexNow and budgets';
    }
    public function scope(): McpScope
    {
        return McpScope::Projects;
    }
    public function description(): string
    {
        return 'Stored project alerts (also requires opportunities:read), report section/schedule status without email addresses, IndexNow setup and recent submissions (kind indexnow; the verified host, sitemap and results, never the key), or workspace AI/DataForSEO budget totals (also requires current integrations.manage permission). No secrets, webhook endpoints, logs, emails or changes. Digest preferences are only the current user’s.';
    }
    public function inputSchema(): array
    {
        return Present::projectSchema(['kind' => ['type' => 'string', 'enum' => ['alerts', 'reports', 'budget', 'indexnow'], 'default' => 'alerts'], 'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90, 'default' => 28], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10]]);
    }
    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $kind = ToolContext::choice($arguments, 'kind', 'alerts', ['alerts', 'reports', 'budget', 'indexnow']);
        $base = ['project_id' => $project->publicId, 'kind' => $kind];
        if ($kind === 'indexnow') {
            $settings = $this->indexNow?->settings($project->id);

            return [...$base, 'configured' => $settings !== null, 'verified' => $settings?->isVerified() ?? false, 'verified_at' => $settings?->verifiedAt, 'host' => $settings?->host,
                'sitemap_url' => $settings?->sitemapUrl, 'auto_submit' => $settings !== null && $settings->autoSubmit, 'sitemap_checked_at' => $settings?->sitemapCheckedAt, 'last_error' => $settings?->lastError,
                'submissions' => $settings !== null && $this->indexNow !== null ? $this->indexNow->submissions($project->id, ToolContext::int($arguments, 'limit', 10, 1, 50)) : [],
                'notice' => 'IndexNow tells participating search engines (Bing, Yandex and others, not Google) that URLs changed; an accepted submission is not indexing. The key itself is not returned.'];
        }
        if ($kind === 'budget') {
            if (!$this->auth->can($context->access->user->subject(), Permission::IntegrationsManage, $project->workspaceId)) {
                throw new ToolError('Budget information requires integrations.manage permission.');
            }
            $providers = [];
            foreach (['ai_assistant', 'dataforseo'] as $provider) {
                $budget = $this->usage->budget($project->workspaceId, $provider);
                $usage = $this->usage->summary($project->workspaceId, $provider);
                $providers[] = ['provider' => $provider, 'daily_limit_usd' => $budget->dailyLimit, 'monthly_limit_usd' => $budget->monthlyLimit, 'operation_limit_usd' => $budget->operationLimit, 'today_usd' => $usage->today, 'month_usd' => $usage->month, 'requests_today' => $usage->requestsToday, 'requests_month' => $usage->requestsMonth];
            }
            return [...$base, 'providers' => $providers, 'notice' => 'Workspace-wide local ledger totals, UTC periods; pending/uncertain reservations count conservatively. Not the provider invoice.'];
        }
        if ($kind === 'reports') {
            $schedule = $this->reports->find($project->id);
            $settings = $this->alerts->settings($context->access->user->id);
            return [...$base, 'sections' => $this->reports->sections($project->id)->enabled, 'schedule' => $schedule !== null ? ['enabled' => $schedule->enabled, 'frequency' => $schedule->frequency, 'last_period' => $schedule->lastPeriod, 'last_sent_at' => $schedule->lastSentAt] : null, 'my_digest' => ['enabled' => $settings->digest, 'ai_summary' => $settings->digestAi], 'notice' => 'Recipients and delivery errors are omitted. No report or message is sent by this tool. Scheduled PDF attachments are not supported.'];
        }
        if (!$context->access->token->allows(McpScope::Opportunities)) {
            throw new ToolError('Project alerts also require opportunities:read.');
        }
        $days = ToolContext::int($arguments, 'days', 28, 1, 90);
        $since = $this->clock->now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $rows = array_values(array_filter($this->alerts->recent($project->workspaceId, [$project->id], $since, 500), static fn(Alert $a): bool => $a->projectId === $project->id));
        $thresholds = $this->alerts->thresholds($project->id);
        return [...$base, 'since' => $since, 'stored_sample_limit' => 500, 'sample_count' => count($rows), 'thresholds' => ['traffic_drop_percent' => $thresholds->trafficDrop, 'minimum_clicks' => $thresholds->minimumClicks, 'ranking_cutoff' => $thresholds->rankingCutoff, 'lost_domain_rank' => $thresholds->lostDomainRank], 'alerts' => array_map(static fn(Alert $a): array => ['alert_id' => $a->publicId, 'type' => $a->type->value, 'title' => $a->title, 'details' => $a->lines, 'created_at' => $a->createdAt], array_slice($rows, 0, ToolContext::int($arguments, 'limit', 10, 1, 100)))];
    }
}

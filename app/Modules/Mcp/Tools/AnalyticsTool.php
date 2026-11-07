<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

final readonly class AnalyticsTool implements McpTool
{
    public function __construct(private Ga4Repository $data, private Clock $clock, private ?\SeoControlCenter\Modules\Analytics\AiReferralService $referrals = null) {}
    public function name(): string
    {
        return 'analytics_summary';
    }
    public function title(): string
    {
        return 'GA4 organic landing pages';
    }
    public function scope(): McpScope
    {
        return McpScope::Pages;
    }
    public function description(): string
    {
        return 'Stored GA4 Organic Search landing-page sessions, key events/conversions and revenue, totals and previous equal window, coverage/currency/quality, optional daily trend and paginated URLs, plus `ai_referrals`: visits, key events and revenue from AI assistants (ChatGPT, Perplexity, Gemini, Copilot …) in all channels, last 28 imported days against the 28 before. Reads no credentials and starts no import. Complete totals are null for partial or limited/thresholded data; observed totals remain descriptive evidence.';
    }
    public function inputSchema(): array
    {
        return Present::projectSchema(['from' => ['type' => 'string', 'format' => 'date'], 'to' => ['type' => 'string', 'format' => 'date'], 'url' => ['type' => 'string', 'maxLength' => 2048], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10], 'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0], 'include_daily' => ['type' => 'boolean', 'default' => false]]);
    }
    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $metadata = $this->data->metadata($project->id);
        $range = ReadInput::range($arguments, DateRange::endingOn($metadata['to'] ?? $this->clock->now()->modify('-1 day')->format('Y-m-d'), 28));
        $url = ReadInput::url($arguments);
        $limit = ToolContext::int($arguments, 'limit', 10, 1, 100);
        $offset = ToolContext::int($arguments, 'offset', 0, 0, 100000);
        $current = $this->period($project->id, $range, $url, ($arguments['include_daily'] ?? false) === true);
        $previous = $this->period($project->id, $range->previous(), $url, false);
        return ['project_id' => $project->publicId, 'channel' => 'Organic Search', 'url' => $url, 'current' => $current, 'previous' => $previous, 'total_urls' => $current['observed_totals']['pages'] ?? 0, 'offset' => $offset, 'next_offset' => $offset + $limit < ($current['observed_totals']['pages'] ?? 0) ? $offset + $limit : null, 'landing_pages' => $metadata !== null ? $this->data->landingPages($project->id, $range, $limit, $url, offset: $offset) : [], 'notice' => 'GA4 conversions/key events and revenue depend on property tracking. Association with an SEO change is not causation. Missing imports are not zero.', 'ai_referrals' => $this->aiReferrals($project)];
    }
    /**
     * Visits from AI assistants, all channels, last 28 imported days against the 28 before (0.43.0).
     *
     * @return array<string, mixed>|null
     */
    private function aiReferrals(\SeoControlCenter\Domain\Projects\Project $project): ?array
    {
        $page = $this->referrals?->page($project);
        if ($page === null || $page['current'] === null) {
            return $page === null ? null : ['imported' => false, 'error' => $page['import']['error'] ?? null];
        }
        return ['imported' => true, 'from' => $page['current']->fromString(), 'to' => $page['current']->toString(), 'previous_comparable' => $page['comparable'], 'currency' => $page['import']['currency'] ?? null, 'limited' => $page['import']['limited'] ?? false,
            'totals' => $page['totals'], 'assistants' => $page['assistants'], 'notice' => 'Counts only visits whose referrer or utm_source names an assistant; app visits often arrive as direct traffic, so this is a lower bound.'];
    }
    /** @return array<string, mixed> */
    private function period(int $projectId, DateRange $range, ?string $url, bool $daily): array
    {
        $metadata = $this->data->metadata($projectId, $range);
        $covered = $this->data->coveredDays($projectId, $range);
        $observed = $metadata !== null && $covered > 0 ? $this->data->totals($projectId, $range, $url) : null;
        $complete = $metadata !== null && $covered === $range->days() && !$metadata['limited'] && !$metadata['thresholded'];
        return ['from' => $range->fromString(), 'to' => $range->toString(), 'metadata' => $metadata, 'covered_days' => $covered, 'expected_days' => $range->days(), 'complete' => $complete, 'totals' => $complete ? $observed : null, 'observed_totals' => $observed, 'daily' => $daily && $metadata !== null ? $this->data->daily($projectId, $range, $url) : null];
    }
}

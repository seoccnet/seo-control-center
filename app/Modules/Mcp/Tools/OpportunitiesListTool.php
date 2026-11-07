<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

final readonly class OpportunitiesListTool implements McpTool
{
    public function __construct(private OpportunityRepository $opportunities, private \SeoControlCenter\Modules\Opportunities\OpportunityCatalog $catalog, private \SeoControlCenter\Modules\Opportunities\OpportunityService $service) {}

    public function name(): string
    {
        return 'opportunities_list';
    }

    public function title(): string
    {
        return 'SEO opportunities';
    }

    public function description(): string
    {
        return 'Rule-based SEO opportunities of the project, highest priority first, each with its evidence (the data that triggered it), impact (0-100), confidence (0-100), effort (1 low - 3 high) and priority. Keyword findings also carry a BID check (business potential, format fit and rankability, each 0-3, with a target/consider/reconsider verdict) and a zero-click risk estimate (high/medium/low). These come from fixed rules on the project\'s data, not from an AI model.';
    }

    public function scope(): McpScope
    {
        return McpScope::Opportunities;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'opportunity_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'Optional scoped finding UUID for its detailed calculation, coverage, supporting signals and action plan.'],
            'page_url' => ['type' => 'string', 'maxLength' => 2048],
            'min_impact' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'default' => 0],
            'effort' => ['type' => 'integer', 'enum' => [1, 2, 3]],
            'data_basis' => ['type' => 'string', 'enum' => \SeoControlCenter\Domain\Opportunities\OpportunityFilter::BASES],
            'verdict' => ['type' => 'string', 'enum' => \SeoControlCenter\Domain\Opportunities\OpportunityFilter::VERDICTS, 'description' => 'Only findings with this BID verdict (needs a business profile).'],
            'zero_click' => ['type' => 'string', 'enum' => \SeoControlCenter\Domain\Opportunities\OpportunityFilter::ZERO_CLICK, 'description' => 'Only findings with this zero-click risk estimate.'],
            'view' => ['type' => 'string', 'enum' => ['findings', 'weekly'], 'default' => 'findings'],
            'status' => ['type' => 'string', 'enum' => StoredOpportunity::STATUSES, 'default' => 'open'],
            'type' => ['type' => 'string', 'enum' => array_map(static fn(OpportunityType $t): string => $t->value, OpportunityType::cases())],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        if (isset($arguments['opportunity_id'])) {
            $id = $arguments['opportunity_id'];
            if (!is_string($id) || !\SeoControlCenter\Core\Support\Uuid::isValid($id)) {
                throw new \SeoControlCenter\Modules\Mcp\Server\ToolError('A valid opportunity_id is required.');
            }
            $detail = $this->service->detail($project, $id);
            $detail['opportunity'] = $detail['opportunity']->present();
            $detail['supporting'] = array_map(static fn(StoredOpportunity $row): array => $row->present(), $detail['supporting']);
            $detail['action_plan'] = $detail['action_plan']->toArray();
            return ['project_id' => $project->publicId, ...$detail, 'notice' => 'Stored local evidence, not a causal claim. Snooze/status changes are made in the authenticated UI; this tool is read-only.',
                'action_plan_notice' => 'action_plan is SEOcc guidance for this kind of finding: when it applies, what to prepare, change, keep and measure, and when to revert. It never affects impact, value or ordering.'];
        }
        $status = ToolContext::choice($arguments, 'status', StoredOpportunity::OPEN, StoredOpportunity::STATUSES);
        $type = isset($arguments['type'])
            ? OpportunityType::from(ToolContext::choice($arguments, 'type', '', array_map(static fn(OpportunityType $t): string => $t->value, OpportunityType::cases())))
            : null;
        $limit = ToolContext::int($arguments, 'limit', 25, 1, 100);
        $offset = ToolContext::int($arguments, 'offset', 0, 0, 100000);
        try {
            $filters = \SeoControlCenter\Domain\Opportunities\OpportunityFilter::fromArray($arguments);
        } catch (\InvalidArgumentException $error) {
            throw new \SeoControlCenter\Modules\Mcp\Server\ToolError($error->getMessage());
        }
        $snapshot = $this->catalog->list($project->id, $status, $type, $filters);
        $view = ToolContext::choice($arguments, 'view', 'findings', ['findings', 'weekly']);
        if ($view === 'weekly') {
            $snapshot['rows'] = (new \SeoControlCenter\Domain\Opportunities\OpportunityPrioritizer())->weekly($snapshot['rows']);
        }
        $total = count($snapshot['rows']);

        return [
            'project_id' => $project->publicId,
            'status' => $status,
            'view' => $view,
            'ordering_notice' => 'Unblocked findings precede dependent work, then priority and stable UUID. Weekly view caps each primary type at three and selects at most ten.',
            'coverage' => \SeoControlCenter\Domain\Opportunities\SignalCoverage::summary($this->opportunities->coverage($project->id)),
            'coverage_notice' => 'Missing or stale sources preserve existing findings and scores. Coverage refers to the last analysis, not live provider availability.',
            'snooze_notice' => 'Snoozed findings resume at the next analysis on or after their project-local date. Dates and workflow decisions are preserved across equivalent signals.',
            'last_analyzed_at' => $this->opportunities->lastAnalyzedAt($project->id),
            'total' => $total,
            'catalog_truncated' => $snapshot['truncated'],
            'consolidation_notice' => 'One primary finding per normalized query or page path; overlapping rule estimates are not added. Done/dismissed decisions for a subject suppress duplicate open findings. Open listing is withheld if the 5,000-row catalog is incomplete.',
            'offset' => $offset,
            'next_offset' => $offset + $limit < $total ? $offset + $limit : null,
            'opportunities' => array_map(static fn(StoredOpportunity $o): array => [
                'opportunity_id' => $o->publicId,
                'type' => $o->type->value,
                'title' => $o->title,
                'explanation' => $o->explanation,
                'evidence' => $o->evidence,
                'impact' => $o->impact,
                'estimated_monthly_clicks' => $o->estimatedClicks,
                'raw_estimated_monthly_clicks' => $o->rawEstimatedClicks,
                'confidence' => $o->confidence,
                'effort' => $o->effort,
                'priority' => $o->priority,
                'bid_verdict' => $o->verdict(),
                'business_potential' => $o->businessPotential(),
                'zero_click_risk' => $o->zeroClickRisk(),
                'ordering_reason' => \SeoControlCenter\Domain\Opportunities\OpportunityPrioritizer::explanation($o, null),
                'score_breakdown' => \SeoControlCenter\Domain\Opportunities\ScoreBreakdown::of($o),
                'status' => $o->status,
                'snoozed_until' => $o->snoozedUntil,
                'first_detected_at' => $o->firstDetectedAt,
                'last_detected_at' => $o->lastDetectedAt,
            ], array_slice($snapshot['rows'], $offset, $limit)),
        ];
    }
}

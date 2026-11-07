<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Modules\Mcp\Server\McpWriteTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;
use SeoControlCenter\Modules\Opportunities\OpportunityService;

/**
 * opportunity_set_status (ADR-036): open, done, dismissed or snoozed until
 * a date, through OpportunityService. Opportunities have no edit revision,
 * so expected_status (from the latest read) guards concurrent changes.
 */
final readonly class OpportunityStatusTool implements McpWriteTool
{
    private const array STATUSES = [StoredOpportunity::OPEN, StoredOpportunity::DONE, StoredOpportunity::DISMISSED, StoredOpportunity::SNOOZED];

    public function __construct(private OpportunityService $service, private OpportunityRepository $opportunities) {}

    public function name(): string
    {
        return 'opportunity_set_status';
    }

    public function title(): string
    {
        return 'Change opportunity status';
    }

    public function description(): string
    {
        return 'Set an opportunity to done (the team acted on it), dismissed (not relevant), open (reopen) or snoozed until a date (snooze_until, within a year). Pass expected_status, the status from your latest opportunities_list read; a different current status answers a conflict. Done starts outcome measurement but does not prove the issue is fixed. Only change status when the user asked.';
    }

    public function scope(): McpScope
    {
        return McpScope::OpportunitiesWrite;
    }

    public function inputSchema(): array
    {
        $schema = Present::projectSchema([
            'opportunity_id' => ['type' => 'string', 'maxLength' => 36],
            'status' => ['type' => 'string', 'enum' => self::STATUSES],
            'expected_status' => ['type' => 'string', 'enum' => StoredOpportunity::STATUSES, 'description' => 'The status from your latest read.'],
            'snooze_until' => ['type' => 'string', 'description' => 'YYYY-MM-DD in the project timezone; required for snoozed.'],
        ]);
        $schema['required'] = ['project_id', 'opportunity_id', 'status', 'expected_status'];

        return $schema;
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $id = $arguments['opportunity_id'] ?? null;
        if (!is_string($id) || !Uuid::isValid($id)) {
            throw new ToolError('Choose a valid opportunity_id from opportunities_list.');
        }
        $status = ToolContext::choice($arguments, 'status', '', self::STATUSES);
        $expected = ToolContext::choice($arguments, 'expected_status', '', StoredOpportunity::STATUSES);
        $row = $this->opportunities->find($project->id, $id) ?? throw new ToolError('Opportunity not found.');
        if ($row->status !== $expected) {
            throw new ToolError(sprintf('Conflict: the opportunity is now %s, not %s. Read it again before changing it.', $row->status, $expected));
        }
        $until = null;
        if ($status === StoredOpportunity::SNOOZED) {
            $until = $arguments['snooze_until'] ?? null;
            if (!is_string($until) || $until === '') {
                throw new ToolError('snooze_until is required for snoozed.');
            }
            WriteCall::run(fn() => $this->service->snooze($context->access->user, $project, $id, $until, $context->client, $context->via()), 'Opportunity not found.');
        } else {
            WriteCall::run(fn() => $this->service->setStatus($context->access->user, $project, $id, $status, $context->client, $context->via()), 'Opportunity not found.');
        }

        return ['project_id' => $project->publicId, 'saved' => true, 'opportunity_id' => $id, 'from' => $row->status, 'status' => $status, 'snoozed_until' => $until, 'notice' => 'A status is a team decision. Done does not prove the issue is fixed or that later changes in traffic were caused by the work.'];
    }
}

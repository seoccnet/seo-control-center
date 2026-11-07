<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Mcp\Server\McpWriteTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;
use SeoControlCenter\Modules\Opportunities\OpportunityService;

/**
 * opportunity_outcome_confirm (ADR-039, E2): records whether the work behind
 * a completed opportunity was really made, through OpportunityService.
 */
final readonly class OutcomeConfirmTool implements McpWriteTool
{
    public function __construct(private OpportunityService $service) {}

    public function name(): string
    {
        return 'opportunity_outcome_confirm';
    }

    public function title(): string
    {
        return 'Confirm an outcome';
    }

    public function description(): string
    {
        return 'Say whether the change behind a completed opportunity was made: implemented true (confirmed) or false (not_implemented, which removes the result from the estimate calibration). Pass expected_implementation, the state from your latest opportunity_outcomes read; a different current state answers a conflict. Both checkpoints of the completion change together. Only call this when the user told you.';
    }

    public function scope(): McpScope
    {
        return McpScope::OpportunitiesWrite;
    }

    public function inputSchema(): array
    {
        $schema = Present::projectSchema([
            'outcome_id' => ['type' => 'string', 'maxLength' => 36],
            'implemented' => ['type' => 'boolean'],
            'expected_implementation' => ['type' => 'string', 'enum' => \SeoControlCenter\Domain\Opportunities\OpportunityOutcome::IMPLEMENTATION, 'description' => 'The implementation from your latest read.'],
        ]);
        $schema['required'] = ['project_id', 'outcome_id', 'implemented', 'expected_implementation'];

        return $schema;
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $id = $arguments['outcome_id'] ?? null;
        if (!is_string($id) || !Uuid::isValid($id)) {
            throw new ToolError('Choose a valid outcome_id from opportunity_outcomes.');
        }
        if (!is_bool($arguments['implemented'] ?? null)) {
            throw new ToolError('implemented must be true or false.');
        }
        $expected = ToolContext::choice($arguments, 'expected_implementation', '', \SeoControlCenter\Domain\Opportunities\OpportunityOutcome::IMPLEMENTATION);
        $current = null;
        foreach ($this->service->outcomes($project) as $row) {
            if ($row->publicId === $id) {
                $current = $row;
            }
        }
        if ($current !== null && $current->implementation !== $expected) {
            throw new ToolError(sprintf('Conflict: the outcome is now %s, not %s. Read it again before changing it.', $current->implementation, $expected));
        }
        $implemented = $arguments['implemented'];
        $after = WriteCall::run(fn() => $this->service->confirmOutcome($context->access->user, $project, $id, $implemented, $context->client, $context->via()), 'Outcome not found.');

        return ['project_id' => $project->publicId, 'saved' => true, 'outcome_id' => $id, 'from' => $current?->implementation, 'implementation' => $after->implementation, 'notice' => 'A confirmation is the team\'s word, not a measurement. It does not make the observed change caused by the work.'];
    }
}

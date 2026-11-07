<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Investigations\Investigation;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Investigations\InvestigationService;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;

/**
 * investigations_list / investigation_get (ADR-038, D3): saved research
 * notebooks with their dated sources, checks, limitations and notes.
 */
final readonly class InvestigationsTool implements McpTool
{
    public function __construct(private InvestigationService $investigations, private bool $details = false) {}

    public function name(): string
    {
        return $this->details ? 'investigation_get' : 'investigations_list';
    }

    public function title(): string
    {
        return $this->details ? 'Investigation' : 'Investigations';
    }

    public function description(): string
    {
        return $this->details
            ? 'One investigation with all its entries: sources (with the date the evidence was observed and a reference to the stored analysis, lookup, opportunity or URL), checks done, limitations and notes, plus the question, status and conclusion. Pass investigation_id from investigations_list.'
            : 'Saved research notebooks of a project: title, status (open, concluded, archived), entry counts per kind and version. Read them before starting a new line of research to reuse what was already found; 50 per page, newest change first.';
    }

    public function scope(): McpScope
    {
        return McpScope::Keywords;
    }

    public function inputSchema(): array
    {
        if ($this->details) {
            $schema = Present::projectSchema(['investigation_id' => ['type' => 'string', 'maxLength' => 36]]);
            $schema['required'] = ['project_id', 'investigation_id'];

            return $schema;
        }

        return Present::projectSchema([
            'status' => ['type' => 'string', 'enum' => ['', ...Investigation::STATUSES]],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        if ($this->details) {
            $id = $arguments['investigation_id'] ?? null;
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new ToolError('Choose a valid investigation_id from investigations_list.');
            }
            $investigation = WriteCall::run(fn(): Investigation => $this->investigations->get($project, $id), 'Investigation not found.');

            return ['project_id' => $project->publicId, 'investigation' => $investigation->present(true), 'notice' => 'Entries record what was observed and when; they are evidence to weigh, not proof of cause.'];
        }
        $status = ToolContext::choice($arguments, 'status', '', ['', ...Investigation::STATUSES]);
        $offset = is_int($arguments['offset'] ?? null) ? max(0, $arguments['offset']) : 0;
        $list = $this->investigations->list($project, $status !== '' ? $status : null, $offset);

        return [
            'project_id' => $project->publicId,
            'total' => $list['total'],
            'offset' => $offset,
            'next_offset' => $offset + InvestigationService::PER_PAGE < $list['total'] ? $offset + InvestigationService::PER_PAGE : null,
            'investigations' => array_map(static fn(Investigation $i): array => $i->present(), $list['items']),
        ];
    }
}

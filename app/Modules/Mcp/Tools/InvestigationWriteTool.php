<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Investigations\Investigation;
use SeoControlCenter\Domain\Investigations\InvestigationInput;
use SeoControlCenter\Domain\Investigations\InvestigationItem;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Investigations\InvestigationService;
use SeoControlCenter\Modules\Mcp\Server\McpWriteTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;

/**
 * investigation_save / investigation_add (ADR-038, D3) under the opt-in
 * scope investigations:write. Save creates an investigation or updates its
 * title, question, status and conclusion (with expected_version); add
 * appends dated entries, or a finished analysis with its evidence. Neither
 * buys data nor changes the website.
 */
final readonly class InvestigationWriteTool implements McpWriteTool
{
    private const array FIELDS = ['title', 'question', 'status', 'conclusion'];

    public function __construct(private InvestigationService $investigations, private bool $add = false) {}

    public function name(): string
    {
        return $this->add ? 'investigation_add' : 'investigation_save';
    }

    public function title(): string
    {
        return $this->add ? 'Add to an investigation' : 'Save an investigation';
    }

    public function description(): string
    {
        return $this->add
            ? 'Add entries to an investigation: sources (where evidence comes from, with observed_on and a ref to a stored analysis, research lookup, opportunity, topic, page or URL), checks you completed, limitations of the evidence and notes; up to 30 per call. Or pass analysis_id (from research_on_file) to add a finished "Why they outrank you" analysis with its sources, checks and limitations. Record only what you actually read or checked.'
            : 'Start an investigation (omit investigation_id; pass client_request_id so a retry does not create two) or update one (investigation_id and expected_version from investigation_get; only the fields you pass change). Status: open, concluded (requires a conclusion) or archived. Use it to keep the question, the evidence and the conclusion of a piece of research together.';
    }

    public function scope(): McpScope
    {
        return McpScope::InvestigationsWrite;
    }

    public function inputSchema(): array
    {
        if ($this->add) {
            $item = ['type' => 'object', 'properties' => [
                'kind' => ['type' => 'string', 'enum' => InvestigationItem::KINDS],
                'label' => ['type' => 'string', 'maxLength' => 255],
                'detail' => ['type' => 'string', 'maxLength' => 2000],
                'ref_type' => ['type' => 'string', 'enum' => InvestigationItem::REF_TYPES],
                'ref_id' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'The ID (analysis, opportunity, topic), kind[:subject] of a research lookup, or the URL.'],
                'observed_on' => ['type' => 'string', 'description' => 'YYYY-MM-DD the evidence was observed; not in the future.'],
            ], 'required' => ['kind', 'label']];
            $schema = Present::projectSchema([
                'investigation_id' => ['type' => 'string', 'maxLength' => 36],
                'items' => ['type' => 'array', 'maxItems' => InvestigationInput::MAX_ITEMS_PER_CALL, 'items' => $item],
                'analysis_id' => ['type' => 'string', 'maxLength' => 36],
                'client_request_id' => WriteCall::requestIdSchema(),
            ]);
            $schema['required'] = ['project_id', 'investigation_id'];

            return $schema;
        }
        $schema = Present::projectSchema([
            'investigation_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'Omit to start a new investigation.'],
            'expected_version' => WriteCall::versionSchema(),
            'title' => ['type' => 'string', 'maxLength' => 200],
            'question' => ['type' => 'string', 'maxLength' => 2000],
            'status' => ['type' => 'string', 'enum' => Investigation::STATUSES],
            'conclusion' => ['type' => 'string', 'maxLength' => 5000],
            'client_request_id' => WriteCall::requestIdSchema(),
        ]);
        $schema['required'] = ['project_id'];

        return $schema;
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $user = $context->access->user;
        if ($this->add) {
            $id = self::id($arguments['investigation_id'] ?? null, 'investigation_id');
            $analysis = $arguments['analysis_id'] ?? null;
            $items = $arguments['items'] ?? null;
            if ($analysis !== null) {
                $analysisId = self::id($analysis, 'analysis_id');
                $saved = WriteCall::run(fn(): Investigation => $this->investigations->addAnalysis($user, $project, $id, $analysisId, $context->client, $context->via()), 'Investigation or finished analysis not found.');
            } elseif (is_array($items) && array_is_list($items) && $items !== []) {
                $saved = WriteCall::run(fn(): Investigation => $this->investigations->addItems($user, $project, $id, array_values(array_filter($items, is_array(...))), $context->client, $context->via()), 'Investigation not found.');
            } else {
                throw new ToolError('Pass items (1 to 30 entries) or analysis_id.');
            }

            return ['project_id' => $project->publicId, 'saved' => true, 'investigation' => $saved->present(), 'notice' => 'Entries added. They record what was observed; nothing was bought.'];
        }
        $id = isset($arguments['investigation_id']) ? self::id($arguments['investigation_id'], 'investigation_id') : null;
        $data = [];
        $version = null;
        if ($id !== null) {
            $version = WriteCall::expectedVersion($arguments);
            $current = WriteCall::run(fn(): Investigation => $this->investigations->get($project, $id), 'Investigation not found.');
            $data = ['title' => $current->title, 'question' => $current->question, 'status' => $current->status, 'conclusion' => $current->conclusion];
        }
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $arguments)) {
                if (!is_string($arguments[$field])) {
                    throw new ToolError($field . ' must be a string.');
                }
                $data[$field] = $arguments[$field];
            }
        }
        $saved = WriteCall::run(
            fn(): Investigation => $this->investigations->save($user, $project, $data, $context->client, $id, $version, $context->via()),
            'Investigation not found.',
            $id === null ? null : fn(): int => $this->investigations->get($project, $id)->revision,
        );

        return ['project_id' => $project->publicId, 'saved' => true, 'investigation' => $saved->present()];
    }

    private static function id(mixed $value, string $field): string
    {
        if (!is_string($value) || !Uuid::isValid($value)) {
            throw new ToolError('Choose a valid ' . $field . '.');
        }

        return $value;
    }
}

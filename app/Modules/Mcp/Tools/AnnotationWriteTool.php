<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Annotations\AnnotationInput;
use SeoControlCenter\Modules\Annotations\AnnotationService;
use SeoControlCenter\Modules\Mcp\Server\McpWriteTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;

/**
 * annotation_create / annotation_update (ADR-036): dated notes on the SEO
 * change timeline, through AnnotationService. Updates merge the given
 * fields onto the current note and require its version.
 */
final readonly class AnnotationWriteTool implements McpWriteTool
{
    private const array FIELDS = ['date', 'type', 'title', 'note', 'url'];

    public function __construct(private AnnotationService $annotations, private bool $update = false, private ?\SeoControlCenter\Modules\Annotations\MeasurementPlanService $plans = null) {}

    public function name(): string
    {
        return $this->update ? 'annotation_update' : 'annotation_create';
    }

    public function title(): string
    {
        return $this->update ? 'Edit a change note' : 'Add a change note';
    }

    public function description(): string
    {
        return $this->update
            ? 'Edit a change note on the SEO timeline. Pass annotation_id and expected_version from annotations_list; only the fields you pass change. A version mismatch answers a conflict: read the note again instead of retrying. Never edits the website.'
            : 'Record a change that was actually made to the website (title, content, links, technical, release, other) on its real date, optionally for one exact page URL. Use it only when the user confirms the change happened; it is evidence for later impact comparisons, not a plan. Never edits the website.';
    }

    public function scope(): McpScope
    {
        return McpScope::AnnotationsWrite;
    }

    public function inputSchema(): array
    {
        $fields = [
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD (UTC), the day the change went live; not in the future.'],
            'type' => ['type' => 'string', 'enum' => array_keys(AnnotationInput::KINDS)],
            'title' => ['type' => 'string', 'maxLength' => 120],
            'note' => ['type' => 'string', 'maxLength' => 2000],
            'url' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'Exact page URL on the project domain; blank for a site-wide change.'],
        ];
        if ($this->update) {
            $schema = Present::projectSchema(['annotation_id' => ['type' => 'string', 'maxLength' => 36], 'expected_version' => WriteCall::versionSchema(), ...$fields]);
            $schema['required'] = ['project_id', 'annotation_id', 'expected_version'];

            return $schema;
        }
        $fields['measure'] = ['type' => 'boolean', 'description' => 'For a change with a url: draft a measurement plan for the page and freeze its baseline now (default true).'];
        $schema = Present::projectSchema([...$fields, 'client_request_id' => WriteCall::requestIdSchema()]);
        $schema['required'] = ['project_id', 'date', 'type', 'title'];

        return $schema;
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $data = [];
        $id = null;
        $version = null;
        if ($this->update) {
            $id = $arguments['annotation_id'] ?? null;
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new ToolError('Choose a valid annotation_id from annotations_list.');
            }
            $version = WriteCall::expectedVersion($arguments);
            $current = WriteCall::run(fn() => $this->annotations->get($project, $id), 'Change note not found.');
            $data = ['date' => $current->date, 'kind' => $current->kind, 'title' => $current->title, 'note' => $current->note, 'url' => $current->url ?? ''];
        }
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $arguments)) {
                if (!is_string($arguments[$field])) {
                    throw new ToolError($field . ' must be a string.');
                }
                $data[$field === 'type' ? 'kind' : $field] = $arguments[$field];
            }
        }
        $saved = WriteCall::run(
            fn() => $this->annotations->save($context->access->user, $project, $data, $context->client, $id, $version, $context->via()),
            'Change note not found.',
            $id === null ? null : fn(): int => $this->annotations->get($project, $id)->revision,
        );

        $measure = !array_key_exists('measure', $arguments) || $arguments['measure'] === true;
        $plan = !$this->update && $measure ? $this->plans?->draft($context->access->user, $project, $saved, $context->client) : null;

        return ['project_id' => $project->publicId, 'saved' => true, 'annotation' => $saved->present(), 'measurement_plan' => $plan, 'notice' => 'Saved on the change timeline. A note records a change; it does not prove what caused later results.'];
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\ContentBriefs\BriefBuilder;
use SeoControlCenter\Modules\ContentBriefs\BriefInput;
use SeoControlCenter\Modules\ContentBriefs\BriefService;
use SeoControlCenter\Modules\Mcp\Server\McpWriteTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;

/**
 * content_brief_create / content_brief_update (ADR-036), through
 * BriefService. A new brief starts from the same stored-data suggestions
 * as the browser form; the fields passed replace them. Updates merge onto
 * the current brief at its version.
 */
final readonly class ContentBriefWriteTool implements McpWriteTool
{
    private const array BASICS = ['title', 'keyword', 'url', 'status'];

    public function __construct(private BriefService $briefs, private BriefBuilder $builder, private bool $update = false) {}

    public function name(): string
    {
        return $this->update ? 'content_brief_update' : 'content_brief_create';
    }

    public function title(): string
    {
        return $this->update ? 'Edit a content brief' : 'Create a content brief';
    }

    public function description(): string
    {
        return $this->update
            ? 'Edit a saved content brief. Pass brief_id and expected_version (version from content_brief_details); only the fields you pass change. A version mismatch answers a conflict: read the brief again. Status published means the page is live in the CMS; it never publishes anything itself.'
            : 'Create a content brief for a target keyword, optionally for an existing page URL, a tracked keyword (keyword_id) or an opportunity (opportunity_id). Sections you leave out are prefilled from stored SEOcc data, like the browser form, and the evidence snapshot is captured from stored data. No provider call; nothing is published.';
    }

    public function scope(): McpScope
    {
        return McpScope::BriefsWrite;
    }

    public function inputSchema(): array
    {
        $fields = [
            'title' => ['type' => 'string', 'maxLength' => 160],
            'keyword' => ['type' => 'string', 'maxLength' => 200],
            'url' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'Exact page URL on the project domain; blank for a new page.'],
            'status' => ['type' => 'string', 'enum' => array_keys(BriefInput::STATUSES)],
        ];
        foreach (BriefInput::FIELDS as $key => $label) {
            $fields[$key] = ['type' => 'string', 'maxLength' => match ($key) {
                'seo_title' => 200, 'meta_description' => 500, default => 4000,
            }, 'description' => $label . '.'];
        }
        if ($this->update) {
            $schema = Present::projectSchema(['brief_id' => ['type' => 'string', 'maxLength' => 36], 'expected_version' => WriteCall::versionSchema(), ...$fields]);
            $schema['required'] = ['project_id', 'brief_id', 'expected_version'];

            return $schema;
        }
        $schema = Present::projectSchema([
            ...$fields,
            'keyword_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'Optional tracked keyword, from keywords_list.'],
            'opportunity_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'Optional opportunity, from opportunities_list.'],
            'client_request_id' => WriteCall::requestIdSchema(),
        ]);
        $schema['required'] = ['project_id'];

        return $schema;
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $given = [];
        foreach ([...self::BASICS, ...array_keys(BriefInput::FIELDS)] as $field) {
            if (array_key_exists($field, $arguments)) {
                if (!is_string($arguments[$field])) {
                    throw new ToolError($field . ' must be a string.');
                }
                $given[$field] = $arguments[$field];
            }
        }
        if ($this->update) {
            $id = $arguments['brief_id'] ?? null;
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new ToolError('Choose a valid brief_id from content_briefs_list.');
            }
            $version = WriteCall::expectedVersion($arguments);
            $current = WriteCall::run(fn() => $this->briefs->get($project, $id), 'Content brief not found.');
            $data = [...$current->input->form(), ...$given, 'revision' => (string) $version];
            $brief = WriteCall::run(
                fn() => $this->briefs->save($context->access->user, $project, $data, $context->client, $id, $context->via()),
                'Content brief not found.',
                fn(): int => $this->briefs->get($project, $id)->revision,
                'The content brief changed since your last read. Read it again with content_briefs_list and retry with its current version.',
            );
        } else {
            $links = [];
            foreach (['keyword_id', 'opportunity_id'] as $key) {
                $value = $arguments[$key] ?? '';
                if (!is_string($value) || ($value !== '' && !Uuid::isValid($value))) {
                    throw new ToolError($key . ' must be an ID from SEOcc.');
                }
                $links[$key] = $value;
            }
            WriteCall::run(fn() => $this->briefs->authorizeEdit($context->access->user, $project));
            $seed = WriteCall::run(fn() => $this->builder->build($project, mb_substr($given['keyword'] ?? '', 0, 200), $given['url'] ?? '', $links['keyword_id'], $links['opportunity_id']), 'Keyword or opportunity not found.');
            $data = [...$seed['input'], 'status' => 'draft', ...$given, ...$links];
            $brief = WriteCall::run(fn() => $this->briefs->save($context->access->user, $project, $data, $context->client, null, $context->via()), 'Keyword or opportunity not found.');
        }

        return ['project_id' => $project->publicId, 'saved' => true, 'notice' => BriefBuilder::NOTICE, 'brief' => $brief->present()];
    }
}

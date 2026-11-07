<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Mcp\Server\McpWriteTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskInput;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskService;

/**
 * seo_task_save (ADR-036): the team work plan of one opportunity, through
 * TaskService. Creates it at expected_version 0, otherwise merges the given
 * fields onto the current task at its version.
 */
final readonly class SeoTaskSaveTool implements McpWriteTool
{
    /** Argument => TaskInput field */
    private const array FIELDS = ['status' => 'status', 'assignee_id' => 'assignee', 'due_date' => 'due', 'notes' => 'notes', 'url' => 'url', 'brief_id' => 'brief', 'annotation_id' => 'annotation'];

    public function __construct(private TaskService $tasks) {}

    public function name(): string
    {
        return 'seo_task_save';
    }

    public function title(): string
    {
        return 'Save a team task';
    }

    public function description(): string
    {
        return 'Create or update the team task of one opportunity: status (todo, in_progress, done), assignee (a member ID from seo_tasks_list, blank to unassign), due date, notes, exact page URL and linked brief or change note. Pass expected_version 0 when the opportunity has no task yet, otherwise the version from seo_tasks_list; only the fields you pass change. Task status is team progress, separate from the finding status (use opportunity_set_status for that). Done does not prove the finding is fixed.';
    }

    public function scope(): McpScope
    {
        return McpScope::TasksWrite;
    }

    public function inputSchema(): array
    {
        $schema = Present::projectSchema([
            'opportunity_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'From opportunities_list or seo_tasks_list.'],
            'expected_version' => WriteCall::versionSchema('0 when the opportunity has no task yet, otherwise the version from seo_tasks_list.'),
            'status' => ['type' => 'string', 'enum' => array_keys(TaskInput::STATUSES)],
            'assignee_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'Member public ID with edit access; blank to unassign.'],
            'due_date' => ['type' => 'string', 'maxLength' => 10, 'description' => 'YYYY-MM-DD in the project timezone; blank to clear.'],
            'notes' => ['type' => 'string', 'maxLength' => 5000],
            'url' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'Exact page URL on the project domain; blank to clear.'],
            'brief_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'A content brief in this project; blank to unlink.'],
            'annotation_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'A change note in this project; blank to unlink.'],
            'client_request_id' => WriteCall::requestIdSchema(),
        ]);
        $schema['required'] = ['project_id', 'opportunity_id', 'expected_version'];

        return $schema;
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $id = $arguments['opportunity_id'] ?? null;
        if (!is_string($id) || !\SeoControlCenter\Core\Support\Uuid::isValid($id)) {
            throw new ToolError('Choose a valid opportunity_id from opportunities_list.');
        }
        $version = WriteCall::expectedVersion($arguments);
        $current = WriteCall::run(fn() => $this->tasks->get($project, $id), 'Opportunity not found.');
        $data = ($current === null ? new TaskInput() : $current->input)->form();
        foreach (self::FIELDS as $argument => $field) {
            if (array_key_exists($argument, $arguments)) {
                if (!is_string($arguments[$argument])) {
                    throw new ToolError($argument . ' must be a string.');
                }
                $data[$field] = $arguments[$argument];
            }
        }
        $data['revision'] = (string) $version;
        WriteCall::run(
            fn() => $this->tasks->save($context->access->user, $project, $id, $data, $context->client, $context->via()),
            'Opportunity not found.',
            fn(): int => $this->tasks->get($project, $id)->revision ?? 0,
            'The task changed since your last read. Read it again with seo_tasks_list and retry with its current version.',
        );
        $task = $this->tasks->get($project, $id) ?? throw new ToolError('The task was not saved.');

        return ['project_id' => $project->publicId, 'saved' => true, 'task' => [
            'opportunity_id' => $task->opportunityId,
            'title' => $task->title,
            'task_status' => $task->input->status,
            'assignee_id' => $task->input->assignee ?: null,
            'due_date' => $task->input->due ?: null,
            'notes' => $task->input->notes,
            'url' => $task->input->url ?: null,
            'brief_id' => $task->input->brief ?: null,
            'annotation_id' => $task->input->annotation ?: null,
            'version' => $task->revision,
            'via' => $task->via,
            'updated_at_utc' => $task->updatedAt,
        ], 'notice' => 'Task progress is a team label, not proof the finding is fixed or that a change caused results.'];
    }
}

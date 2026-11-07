<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Opportunities\Tasks\SeoTask;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskFilter;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskInput;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskRepository;

final class InMemoryTasks implements TaskRepository
{
    /** @var array<string, SeoTask> */
    private array $items = [];
    public function __construct(private readonly OpportunityRepository $opportunities, private readonly Clock $clock) {}
    private function key(Project $project, string $id): string
    {
        return $project->workspaceId . ':' . $project->id . ':' . $id;
    }
    public function find(Project $project, string $opportunityId): ?SeoTask
    {
        $task = $this->items[$this->key($project, $opportunityId)] ?? null;
        $opportunity = $this->opportunities->find($project->id, $opportunityId);
        return $task === null || $opportunity === null ? null : new SeoTask($opportunityId, $opportunity->title, $opportunity->priority, $opportunity->status, $task->input, $task->revision, $task->updatedAt, $task->via);
    }
    public function save(Project $project, string $opportunityId, TaskInput $input, int $revision, ?string $via = null): bool
    {
        $old = $this->find($project, $opportunityId);
        $opportunity = $this->opportunities->find($project->id, $opportunityId);
        if ($opportunity === null || ($old === null ? 0 : $old->revision) !== $revision) {
            return false;
        }
        $this->items[$this->key($project, $opportunityId)] = new SeoTask($opportunityId, $opportunity->title, $opportunity->priority, $opportunity->status, $input, $revision + 1, $this->clock->now()->format('Y-m-d H:i:s'), $via);
        return true;
    }
    /** @return list<SeoTask> */
    private function matching(Project $project, TaskFilter $filter, string $today): array
    {
        $items = [];
        foreach ($this->items as $key => $raw) {
            if ($key !== $this->key($project, $raw->opportunityId)) {
                continue;
            }
            $task = $this->find($project, $raw->opportunityId);
            if ($task === null) {
                continue;
            }
            $i = $task->input;
            if ($filter->status !== '' && $filter->status !== $i->status || $filter->assignee === 'unassigned' && $i->assignee !== '' || $filter->assignee !== '' && $filter->assignee !== 'unassigned' && $filter->assignee !== $i->assignee || $filter->url !== '' && $filter->url !== $i->url || $task->priority < $filter->priority || $filter->overdue && ($i->due === '' || $i->due >= $today || $i->status === 'done') || $filter->search !== '' && !str_contains(mb_strtolower($task->title . ' ' . $i->notes . ' ' . $i->url), mb_strtolower($filter->search))) {
                continue;
            }
            $items[] = $task;
        }
        usort($items, static fn(SeoTask $a, SeoTask $b): int => [$a->input->status === 'done', $a->input->due === '', $a->input->due, -$a->priority] <=> [$b->input->status === 'done', $b->input->due === '', $b->input->due, -$b->priority]);
        return $items;
    }
    public function list(Project $project, TaskFilter $filter, string $today, int $offset): array
    {
        return array_slice($this->matching($project, $filter, $today), max(0, $offset), 25);
    }
    public function count(Project $project, TaskFilter $filter, string $today): int
    {
        return count($this->matching($project, $filter, $today));
    }
}

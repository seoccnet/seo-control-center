<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Tasks;

final readonly class TaskFilter
{
    public function __construct(public string $status = '', public string $assignee = '', public bool $overdue = false, public string $search = '', public string $url = '', public int $priority = 0) {}
    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $text = static fn(string $key, int $max): string => is_string($data[$key] ?? null) ? mb_substr(trim($data[$key]), 0, $max) : '';
        $status = $text('status', 20);
        $assignee = $text('assignee', 36);
        return new self(isset(TaskInput::STATUSES[$status]) ? $status : '', $assignee, ($data['overdue'] ?? '') === '1', $text('search', 200), $text('url', 2048), in_array($data['priority'] ?? '', ['50', '75'], true) ? (int) $data['priority'] : 0);
    }
    /** @return array<string, string|int> */
    public function query(): array
    {
        return ['status' => $this->status, 'assignee' => $this->assignee, 'overdue' => $this->overdue ? '1' : '', 'search' => $this->search, 'url' => $this->url, 'priority' => $this->priority];
    }
}

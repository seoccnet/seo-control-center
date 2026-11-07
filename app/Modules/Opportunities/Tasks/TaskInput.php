<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Tasks;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\ContentBriefs\BriefInput;

final readonly class TaskInput
{
    public const array STATUSES = ['todo' => 'To do', 'in_progress' => 'In progress', 'done' => 'Done'];
    public function __construct(public string $status = 'todo', public string $assignee = '', public string $due = '', public string $notes = '', public string $url = '', public string $brief = '', public string $annotation = '') {}
    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, Project $project): self
    {
        $values = [];
        foreach (['status' => 20, 'assignee' => 36, 'due' => 10, 'notes' => 5000, 'url' => 2048, 'brief' => 36, 'annotation' => 36] as $key => $max) {
            $value = $data[$key] ?? ($key === 'status' ? 'todo' : '');
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                throw new HttpException(422, 'Invalid task field: ' . $key . '.');
            }
            $values[$key] = trim($value);
        }
        if (!isset(self::STATUSES[$values['status']])) {
            throw new HttpException(422, 'Choose a task status.');
        }
        foreach (['assignee', 'brief', 'annotation'] as $key) {
            if ($values[$key] !== '' && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $values[$key]) !== 1) {
                throw new HttpException(422, 'Invalid task reference.');
            }
        }
        if ($values['due'] !== '') {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $values['due']);
            if ($date === false || $date->format('Y-m-d') !== $values['due'] || $values['due'] < '1000-01-01') {
                throw new HttpException(422, 'Use a valid due date.');
            }
        }
        if ($values['url'] !== '') {
            // Reuse the existing exact-page validation, without provider calls.
            BriefInput::fromArray(['title' => 'Task', 'keyword' => 'Task', 'status' => 'draft', 'url' => $values['url']], $project);
        }
        return new self(...$values);
    }
    /** @return array<string, string> */
    public function form(): array
    {
        return get_object_vars($this);
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

/**
 * A per-page list of changes written by the AI assistant, downloadable as CSV.
 *
 * @phpstan-type FixRow array{url: string, issue: string, current: string, suggested: string, note: string}
 */
final readonly class FixPlan
{
    public const array COLUMNS = ['url', 'issue', 'current', 'suggested', 'note'];
    public const int MAX_ROWS = 500;

    /**
     * @param list<FixRow> $rows
     */
    public function __construct(
        public string $publicId,
        public int $workspaceId,
        public int $projectId,
        public int $userId,
        public string $title,
        public array $rows,
        public string $createdAt,
    ) {}

    /**
     * Valid rows from tool input: strings only, trimmed, limited in size.
     *
     * @return list<FixRow>
     */
    public static function rowsFrom(mixed $input): array
    {
        $rows = [];
        foreach (is_array($input) ? $input : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = static fn(string $column): string => is_scalar($row[$column] ?? null) ? mb_substr(trim((string) $row[$column]), 0, 2000) : '';
            $clean = ['url' => $value('url'), 'issue' => $value('issue'), 'current' => $value('current'), 'suggested' => $value('suggested'), 'note' => $value('note')];
            if ($clean['url'] !== '' || $clean['suggested'] !== '') {
                $rows[] = $clean;
            }
            if (count($rows) >= self::MAX_ROWS) {
                break;
            }
        }

        return $rows;
    }

    /**
     * Header and rows for the CSV download.
     *
     * @return list<list<string>>
     */
    public function csvRows(): array
    {
        return [
            ['URL', 'Issue', 'Current', 'Suggested change', 'Note'],
            ...array_map(static fn(array $r): array => [$r['url'], $r['issue'], $r['current'], $r['suggested'], $r['note']], $this->rows),
        ];
    }
}

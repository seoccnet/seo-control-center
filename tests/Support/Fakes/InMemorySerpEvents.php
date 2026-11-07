<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\SerpHistory\SerpChangeDetector;
use SeoControlCenter\Domain\SerpHistory\SerpEvent;
use SeoControlCenter\Domain\SerpHistory\SerpEventRepository;

/**
 * Results-page change events in memory, once per keyword, device, day,
 * kind and subject like the unique key of serp_events.
 */
final class InMemorySerpEvents implements SerpEventRepository
{
    /** @var array<string, array{project_id: int, workspace_id: int, keyword_id: int, device: string, checked_on: string, previous_on: string, kind: string, subject: string, detail: array<string, mixed>}> */
    public array $rows = [];

    public function __construct(private readonly InMemoryKeywords $keywords) {}

    public function record(int $projectId, int $workspaceId, string $device, string $checkedOn, array $events): int
    {
        $added = 0;
        foreach ($events as $e) {
            $key = $e['keyword_id'] . '|' . $device . '|' . $checkedOn . '|' . $e['kind'] . '|' . hash('sha256', $e['subject']);
            if (!isset($this->rows[$key])) {
                $this->rows[$key] = ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'keyword_id' => $e['keyword_id'], 'device' => $device, 'checked_on' => $checkedOn, 'previous_on' => $e['previous_on'], 'kind' => $e['kind'], 'subject' => $e['subject'], 'detail' => $e['detail']];
                ++$added;
            }
        }

        return $added;
    }

    public function list(int $projectId, string $device, array $filters, int $limit, int $offset): array
    {
        $rows = $this->filtered($projectId, $device, $filters);
        usort($rows, static fn(array $a, array $b): int => [$b['checked_on'], $a['keyword']] <=> [$a['checked_on'], $b['keyword']]);

        return array_map(static fn(array $r): SerpEvent => new SerpEvent($r['keyword'], $r['keyword_public_id'], $r['device'], $r['checked_on'], $r['previous_on'], $r['kind'], $r['subject'], $r['detail']), array_slice($rows, $offset, $limit));
    }

    public function count(int $projectId, string $device, array $filters): int
    {
        return count($this->filtered($projectId, $device, $filters));
    }

    public function counts(int $projectId, string $device, string $since): array
    {
        $counts = [];
        foreach ($this->rows as $r) {
            if ($r['project_id'] === $projectId && $r['device'] === $device && $r['checked_on'] >= $since) {
                $counts[$r['kind']] = ($counts[$r['kind']] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function competitors(int $projectId, string $device, string $since): array
    {
        $out = [];
        foreach ($this->rows as $r) {
            if ($r['project_id'] !== $projectId || $r['device'] !== $device || $r['checked_on'] < $since) {
                continue;
            }
            if ($r['kind'] === SerpChangeDetector::NEW_URL && ($r['detail']['own'] ?? false) !== true && is_string($r['detail']['domain'] ?? null)) {
                $out[$r['detail']['domain']] ??= ['new_urls' => 0, 'entered' => 0, 'left' => 0];
                ++$out[$r['detail']['domain']]['new_urls'];
            } elseif (in_array($r['kind'], [SerpChangeDetector::COMPETITOR_ENTERED, SerpChangeDetector::COMPETITOR_LEFT], true)) {
                $out[$r['subject']] ??= ['new_urls' => 0, 'entered' => 0, 'left' => 0];
                ++$out[$r['subject']][$r['kind'] === SerpChangeDetector::COMPETITOR_ENTERED ? 'entered' : 'left'];
            }
        }
        ksort($out);

        return $out;
    }

    public function hasAny(int $projectId): bool
    {
        foreach ($this->rows as $r) {
            if ($r['project_id'] === $projectId) {
                return true;
            }
        }

        return false;
    }

    public function prune(string $before): int
    {
        $n = count($this->rows);
        $this->rows = array_filter($this->rows, static fn(array $r): bool => $r['checked_on'] >= $before);

        return $n - count($this->rows);
    }

    /**
     * @param array{kinds?: list<string>, q?: string, subject?: string, from?: string, to?: string} $filters
     * @return list<array{keyword: string, keyword_public_id: string, device: string, checked_on: string, previous_on: string, kind: string, subject: string, detail: array<string, mixed>}>
     */
    private function filtered(int $projectId, string $device, array $filters): array
    {
        $names = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $k) {
            $names[$k['id']] = [$k['keyword'], $k['public_id']];
        }
        $out = [];
        foreach ($this->rows as $r) {
            [$keyword, $publicId] = $names[$r['keyword_id']] ?? ['', ''];
            if ($r['project_id'] !== $projectId || $r['device'] !== $device
                || (($filters['kinds'] ?? []) !== [] && !in_array($r['kind'], $filters['kinds'], true))
                || (($filters['q'] ?? '') !== '' && !str_contains($keyword, (string) $filters['q']))
                || (($filters['subject'] ?? '') !== '' && !str_contains($r['subject'] . ' ' . json_encode($r['detail']), (string) $filters['subject']))
                || (($filters['from'] ?? '') !== '' && $r['checked_on'] < $filters['from'])
                || (($filters['to'] ?? '') !== '' && $r['checked_on'] > $filters['to'])) {
                continue;
            }
            $out[] = ['keyword' => $keyword, 'keyword_public_id' => $publicId, 'device' => $r['device'], 'checked_on' => $r['checked_on'], 'previous_on' => $r['previous_on'], 'kind' => $r['kind'], 'subject' => $r['subject'], 'detail' => $r['detail']];
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\Research\ResearchResult;
use SeoControlCenter\Modules\Research\ResearchScheduler;

/**
 * Research results and queued lookups in memory.
 */
final class InMemoryResearch implements ResearchRepository, ResearchScheduler
{
    /** @var array<int, list<ResearchResult>> */
    private array $renderedSnapshots = [];

    public function publishRendered(ResearchResult $result, array $items): void
    {
        $current = $this->find($result->projectId, $result->kind, $result->subject);
        if (!$current?->isQueued() || $current->params != $result->params) {
            throw new \RuntimeException('Rendered request changed before publishing.');
        }
        $this->complete($result->projectId, $result->kind, $result->subject, $items);
        $saved = $this->find($result->projectId, $result->kind, $result->subject) ?? throw new \RuntimeException('Missing published result.');
        $this->renderedSnapshots[$result->projectId] = array_slice([$saved, ...($this->renderedSnapshots[$result->projectId] ?? [])], 0, 5);
    }

    public function renderedHistory(int $projectId): array
    {
        return $this->renderedSnapshots[$projectId] ?? [];
    }
    /** @var array<string, ResearchResult> keyed by project:kind:subject */
    public array $results = [];

    /** @var list<array{projectId: int, workspaceId: int, kind: string, subject: string}> */
    public array $queued = [];

    public function __construct(private readonly Clock $clock) {}

    public function fetch(int $projectId, int $workspaceId, string $kind, string $subject): void
    {
        $this->queued[] = ['projectId' => $projectId, 'workspaceId' => $workspaceId, 'kind' => $kind, 'subject' => $subject];
    }

    public function find(int $projectId, string $kind, string $subject = ''): ?ResearchResult
    {
        return $this->results[$projectId . ':' . $kind . ':' . $subject] ?? null;
    }

    public function forKind(int $projectId, string $kind): array
    {
        return array_values(array_filter($this->results, static fn(ResearchResult $r): bool => $r->projectId === $projectId && $r->kind === $kind));
    }

    public function queue(int $projectId, int $workspaceId, string $kind, string $subject, array $params, ?int $userId): void
    {
        $params['_request_id'] = \SeoControlCenter\Core\Support\Uuid::v7($this->clock);
        $old = $this->find($projectId, $kind, $subject);
        $this->results[$projectId . ':' . $kind . ':' . $subject] = new ResearchResult($projectId, $kind, $subject, 'queued', $params, $old !== null ? $old->items : [], null, $this->now(), $old?->fetchedAt);
    }

    public function complete(int $projectId, string $kind, string $subject, array $items): void
    {
        $old = $this->find($projectId, $kind, $subject) ?? throw new \LogicException('Not queued.');
        $params = $old->params;
        unset($params['_batch']);
        $this->results[$projectId . ':' . $kind . ':' . $subject] = new ResearchResult($projectId, $kind, $subject, 'done', $params, $items, null, $old->requestedAt, $this->now());
    }

    public function fail(int $projectId, string $kind, string $subject, string $error, ?string $requestId = null): void
    {
        $old = $this->find($projectId, $kind, $subject) ?? throw new \LogicException('Not queued.');
        if ($requestId !== null && (!$old->isQueued() || ($old->params['_request_id'] ?? null) !== $requestId)) {
            return;
        }
        $this->results[$projectId . ':' . $kind . ':' . $subject] = new ResearchResult($projectId, $kind, $subject, 'failed', $old->params, $old->items, $error, $old->requestedAt, $old->fetchedAt);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $params */
    public function checkpoint(ResearchResult $result, array $params): void
    {
        $old = $this->find($result->projectId, $result->kind, $result->subject);
        if ($old === null || !$old->isQueued() || $old->params !== $result->params || $old->requestedAt !== $result->requestedAt) {
            throw new \RuntimeException('The lookup changed while saving progress.');
        }
        $this->results[$result->projectId . ':' . $result->kind . ':' . $result->subject] = new ResearchResult($old->projectId, $old->kind, $old->subject, $old->status, $params, $old->items, $old->error, $old->requestedAt, $old->fetchedAt);
    }
}

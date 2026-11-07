<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Claims\Claim;
use SeoControlCenter\Modules\ContentBriefs\ClaimRepository;

/** Brief claims with revisions for HTTP tests (ADR-042). */
final class InMemoryClaims implements ClaimRepository
{
    /** @var array<int, array<string, Claim>> */
    private array $claims = [];

    /** @var array<string, list<array{version: int, snapshot: array<string, mixed>, changed_by: ?int, changed_at: string}>> */
    private array $revisions = [];

    public function __construct(private readonly Clock $clock) {}

    public function forBrief(int $projectId, string $briefId): array
    {
        return array_values(array_filter($this->claims[$projectId] ?? [], static fn(Claim $c): bool => $c->briefId === $briefId));
    }

    public function forProject(int $projectId, string $status, int $offset, int $limit): array
    {
        $all = array_values(array_filter(array_reverse($this->claims[$projectId] ?? []), static fn(Claim $c): bool => $status === '' || $c->status === $status));

        return ['items' => array_slice($all, $offset, $limit), 'total' => count($all)];
    }

    public function find(int $projectId, string $publicId): ?Claim
    {
        return $this->claims[$projectId][$publicId] ?? null;
    }

    public function countForBrief(int $projectId, string $briefId): int
    {
        return count($this->forBrief($projectId, $briefId));
    }

    public function add(int $workspaceId, int $projectId, string $briefId, array $data, ?int $userId): Claim
    {
        $claim = $this->make(Uuid::v7($this->clock), $briefId, $data, null, null, 1);

        return $this->claims[$projectId][$claim->publicId] = $claim;
    }

    public function update(int $projectId, string $publicId, int $version, array $data, bool $reviewed, ?int $userId): bool
    {
        $old = $this->claims[$projectId][$publicId] ?? null;
        if ($old === null || $old->version !== $version) {
            return false;
        }
        array_unshift($this->revisions[$publicId], ['version' => $version, 'snapshot' => $old->present(), 'changed_by' => $userId, 'changed_at' => $this->now()]);
        $this->claims[$projectId][$publicId] = $this->make($publicId, $old->briefId, $data, $reviewed ? $userId : $old->reviewedBy, $reviewed ? $this->now() : $old->reviewedAt, $version + 1);

        return true;
    }

    public function revisions(int $projectId, string $publicId): array
    {
        return isset($this->claims[$projectId][$publicId]) ? $this->revisions[$publicId] ?? [] : [];
    }

    public function remove(int $projectId, string $publicId): bool
    {
        if (!isset($this->claims[$projectId][$publicId])) {
            return false;
        }
        unset($this->claims[$projectId][$publicId], $this->revisions[$publicId]);

        return true;
    }

    /** @param array{claim: string, subject: string, claim_scope: array<string, string>, source_url: ?string, publisher: string, checked_on: ?string, passage: string, source_scope: array<string, string>, status: string, note: string} $data */
    private function make(string $id, string $briefId, array $data, ?int $reviewedBy, ?string $reviewedAt, int $version): Claim
    {
        $this->revisions[$id] ??= [];

        return new Claim($id, $briefId, $data['claim'], $data['subject'], $data['claim_scope'], $data['source_url'], $data['publisher'], $data['checked_on'], $data['passage'], $data['source_scope'], $data['status'], $data['note'], $reviewedBy, $reviewedAt, $version, $this->now());
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\ChangeBundles\BundleCheckScheduler;
use SeoControlCenter\Modules\ChangeBundles\ChangeBundleRepository;
use SeoControlCenter\Modules\ChangeBundles\LivePageReader;

/** @phpstan-import-type Bundle from ChangeBundleRepository */
final class InMemoryChangeBundles implements ChangeBundleRepository, BundleCheckScheduler, LivePageReader
{
    /** @var array<int, array<string, Bundle>> by project ID */
    public array $bundles = [];
    /** @var array<string, list<array{kind: string, detail: string, created_at: string}>> */
    public array $events = [];
    /** @var list<array{project_id: int, bundle_id: string, purpose: string}> */
    public array $queued = [];
    /** @var array<string, array<string, int|string|bool|null>|string> live page facts by address */
    public array $live = [];
    public int $reads = 0;

    public function list(Project $project, int $limit): array
    {
        $rows = array_values($this->bundles[$project->id] ?? []);
        usort($rows, static fn(array $a, array $b): int => [$b['updated_at'], $b['public_id']] <=> [$a['updated_at'], $a['public_id']]);

        return array_slice($rows, 0, $limit);
    }

    public function find(Project $project, string $publicId): ?array
    {
        $this->reads++;

        return $this->bundles[$project->id][$publicId] ?? null;
    }

    public function countInProject(int $projectId): int
    {
        return count($this->bundles[$projectId] ?? []);
    }

    public function create(Project $project, string $publicId, string $title, string $url, string $status, array $data, ?int $userId, string $now): void
    {
        $this->bundles[$project->id][$publicId] = ['public_id' => $publicId, 'title' => $title, 'url' => $url, 'status' => $status, 'version' => 1, 'created_at' => $now, 'updated_at' => $now, 'data' => $data];
    }

    public function update(Project $project, string $publicId, string $title, string $url, string $status, array $data, int $expectedVersion, string $now): bool
    {
        $old = $this->find($project, $publicId);
        if ($old === null || $old['version'] !== $expectedVersion) {
            return false;
        }
        $this->bundles[$project->id][$publicId] = [...$old, 'title' => $title, 'url' => $url, 'status' => $status, 'data' => $data, 'version' => $expectedVersion + 1, 'updated_at' => $now];

        return true;
    }

    public function delete(Project $project, string $publicId, int $expectedVersion): bool
    {
        if (($this->bundles[$project->id][$publicId]['version'] ?? null) !== $expectedVersion) {
            return false;
        }
        unset($this->bundles[$project->id][$publicId], $this->events[$publicId]);

        return true;
    }

    public function addEvent(Project $project, string $publicId, string $kind, string $detail, ?int $userId, string $now): void
    {
        if ($this->find($project, $publicId) !== null) {
            $this->events[$publicId] = [['kind' => $kind, 'detail' => $detail, 'created_at' => $now], ...($this->events[$publicId] ?? [])];
        }
    }

    public function events(Project $project, string $publicId): array
    {
        return $this->find($project, $publicId) === null ? [] : array_slice($this->events[$publicId] ?? [], 0, 100);
    }

    public function check(Project $project, string $bundleId, string $purpose): void
    {
        $this->queued[] = ['project_id' => $project->id, 'bundle_id' => $bundleId, 'purpose' => $purpose];
    }

    public function read(string $url): array|string
    {
        return $this->live[$url] ?? 'The page could not be read completely (HTTP 404).';
    }
}

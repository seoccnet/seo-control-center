<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

interface IndexingRepository
{
    /** Reserves at most 500 URLs and 50 watched URLs per project, atomically. */
    public function track(int $projectId, string $binding, string $url, ?bool $watch): bool;
    public function find(int $projectId, string $binding, string $url): ?InspectionRecord;
    /** @return list<InspectionRecord> At most 500 project URLs. */
    public function records(int $projectId, string $binding): array;
    /** @return list<array{project_id: int, workspace_id: int, public_id: string, binding: string, url: string}> */
    public function due(string $before, int $limit): array;
    public function attempted(int $projectId, string $binding, string $url, string $at, ?string $error): void;
    /** Saves success once per job and retains bounded change events, never errors as index evidence. */
    public function save(int $projectId, string $binding, string $url, Inspection $result, string $at, string $jobId): void;
    /** @return list<array{id: int, url: string, before: string, after: string, canonical_changed: bool}> */
    public function changes(int $projectId, string $binding, string $since): array;

    /**
     * Successful checks saved since 0.37.0, newest first; at most 30 are kept per URL.
     *
     * @return list<array{checked_at: string, verdict: string, coverage: string, indexing_state: string, robots: string, last_crawl: ?string, google_canonical: string, user_canonical: string}>
     */
    public function history(int $projectId, string $binding, string $url): array;

    public function addRequest(int $workspaceId, int $projectId, string $publicId, string $url, string $channel, string $requestedOn, string $evidence, string $note, ?int $userId, string $at): void;

    /**
     * Recorded indexing requests for one URL, newest first, at most 50.
     *
     * @return list<array{public_id: string, channel: string, requested_on: string, evidence: string, note: string, created_at: string}>
     */
    public function requests(int $projectId, string $url): array;
}

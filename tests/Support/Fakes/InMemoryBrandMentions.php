<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Research\BrandCheck;
use SeoControlCenter\Modules\Research\BrandMentionRepository;
use SeoControlCenter\Modules\Research\BrandSettings;

final class InMemoryBrandMentions implements BrandMentionRepository
{
    /** @var array<string, BrandSettings> */ private array $settings = [];
    /** @var array<string, Project> */ private array $projects = [];
    /** @var array<string, string> */ private array $attempts = [];
    /** @var array<string, array<string, BrandCheck>> */ private array $checks = [];
    public function __construct(private readonly Clock $clock) {}
    private function scope(Project $p): string
    {
        return $p->workspaceId . ':' . $p->id;
    }
    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
    public function settings(Project $project): ?BrandSettings
    {
        return $this->settings[$this->scope($project)] ?? null;
    }
    public function save(Project $project, BrandSettings $settings): void
    {
        $scope = $this->scope($project);
        if ($this->settings($project)?->key($project) !== $settings->key($project)) {
            unset($this->attempts[$scope]);
        }
        $this->settings[$scope] = $settings;
        $this->projects[$scope] = $project;
    }
    public function due(string $before): array
    {
        $rows = [];
        foreach ($this->settings as $scope => $s) {
            if ($s->weekly && (!isset($this->attempts[$scope]) || $this->attempts[$scope] <= $before)) {
                $p = $this->projects[$scope];
                $rows[] = ['workspaceId' => $p->workspaceId, 'projectPublicId' => $p->publicId];
            }
        }
        return $rows;
    }
    public function create(Project $project, BrandSettings $settings, int $userId, bool $scheduled): BrandCheck
    {
        foreach ($this->history($project, $settings->key($project), 100) as $c) {
            if (in_array($c->status, ['queued', 'processing'], true) && $c->updatedAt > $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s')) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(409, 'A brand check is already pending.');
            }
        }
        foreach ($this->history($project, $settings->key($project), 100) as $c) {
            $this->fail($project, $c->publicId, 'Superseded after 24 hours by a new check. Review spending for any request already sent.');
        }
        $c = new BrandCheck(Uuid::v7(), $settings->key($project), 'queued', 0, [], [], null, $this->now(), $this->now(), $userId, $scheduled);
        $this->checks[$this->scope($project)][$c->publicId] = $c;
        $this->attempts[$this->scope($project)] = $this->now();
        return $c;
    }
    public function find(Project $project, string $publicId): ?BrandCheck
    {
        return $this->checks[$this->scope($project)][$publicId] ?? null;
    }
    public function history(Project $project, string $key, int $limit): array
    {
        return array_slice(array_values(array_filter(array_reverse($this->checks[$this->scope($project)] ?? []), static fn(BrandCheck $c): bool => $c->key === $key)), 0, max(1, min(100, $limit)));
    }
    public function claim(Project $project, BrandCheck $check): bool
    {
        $current = $this->find($project, $check->publicId);
        if ($current === null || $current->status !== 'queued' || $current->step !== $check->step) {
            return false;
        }
        $this->put($project, $current, 'processing', $current->step, $current->metrics, $current->answers, null);
        return true;
    }
    public function advance(Project $project, BrandCheck $check, array $metrics, array $answers): void
    {
        $current = $this->find($project, $check->publicId);
        if ($current !== null && $current->status === 'processing' && $current->step === $check->step) {
            $this->put($project, $current, $check->step === 3 ? 'completed' : 'queued', $check->step + 1, $metrics, $answers, null);
        }
    }
    public function fail(Project $project, string $publicId, string $message): void
    {
        $c = $this->find($project, $publicId);
        if ($c !== null && in_array($c->status, ['queued', 'processing'], true)) {
            $this->put($project, $c, 'failed', $c->step, $c->metrics, $c->answers, mb_substr($message, 0, 500));
        }
    }
    /**
     * @param array<string, array{mentions: int, ai_search_volume: ?int}> $metrics
     * @param array<string, list<\SeoControlCenter\Domain\Research\AiAnswer>> $answers */
    private function put(Project $p, BrandCheck $c, string $status, int $step, array $metrics, array $answers, ?string $error): void
    {
        $this->checks[$this->scope($p)][$c->publicId] = new BrandCheck($c->publicId, $c->key, $status, $step, $metrics, $answers, $error, $c->createdAt, $this->now(), $c->userId, $c->scheduled);
    }
}

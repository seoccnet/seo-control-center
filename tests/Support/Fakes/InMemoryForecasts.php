<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Forecasting\ForecastEngine;
use SeoControlCenter\Modules\Forecasting\ForecastEvent;
use SeoControlCenter\Modules\Forecasting\ForecastRepository;
use SeoControlCenter\Modules\Forecasting\ForecastSeries;

final class InMemoryForecasts implements ForecastRepository
{
    /** @var array<int, list<ForecastSeries>> */
    public array $sources = [];
    /** @var array<string, array<string, mixed>> */
    public array $saved = [];
    /** @var array<string, list<ForecastEvent>> */
    public array $calendar = [];
    /** @var array<string, array{enabled: bool, lastIssued: ?string, checked: ?string}> */
    public array $states = [];
    public function tracking(Project $project): array
    {
        return $this->states[$project->workspaceId . ':' . $project->id] ?? ['enabled' => false, 'lastIssued' => null, 'checked' => null];
    }
    public function setTracking(Project $project, bool $enabled): void
    {
        $this->states[$project->workspaceId . ':' . $project->id] = [...$this->tracking($project), 'enabled' => $enabled];
    }
    public function checked(Project $project, string $date, bool $issued): void
    {
        $this->states[$project->workspaceId . ':' . $project->id] = [...$this->tracking($project), 'checked' => $date, 'lastIssued' => $issued ? $date : $this->tracking($project)['lastIssued']];
    }
    public function dueProjects(string $date): array
    {
        return [];
    }
    public function observe(Project $project, int $id, float $actual): void
    {
        foreach ($this->saved as &$row) {
            if ($row['project'] === $project->id && $row['workspace'] === $project->workspaceId && $row['id'] === $id) {
                $row['actual'] = $actual;
                $row['observedAt'] = '2026-10-15 00:00:00';
            }
        }
    }
    public function events(Project $project): array
    {
        return $this->calendar[$project->workspaceId . ':' . $project->id] ?? [];
    }
    public function saveEvent(Project $project, ForecastEvent $event): void
    {
        $this->calendar[$project->workspaceId . ':' . $project->id][] = $event;
    }
    public function excludeEvent(Project $project, string $name, string $date, string $excludedOn): void
    {
        $key = $project->workspaceId . ':' . $project->id;
        $this->calendar[$key] = array_map(static fn(ForecastEvent $event): ForecastEvent => $event->name === $name && $event->date === $date ? new ForecastEvent($event->name, $event->date, $event->knownOn, $event->excludedOn ?? $excludedOn) : $event, $this->calendar[$key] ?? []);
    }
    public function series(Project $project, string $asOf): array
    {
        return $this->sources[$project->id] ?? [];
    }
    public function save(Project $project, string $issued, int $horizon, ForecastSeries $series, array $payload): void
    {
        $key = $project->workspaceId . ':' . $project->id . ':' . $issued . ':' . $horizon . ':' . $series->key . ':' . $series->binding;
        $this->saved[$key] ??= ['workspace' => $project->workspaceId, 'project' => $project->id, 'id' => count($this->saved) + 1, 'issued' => $issued, 'horizon' => $horizon, 'version' => ForecastEngine::VERSION, 'binding' => hash('sha256', $series->binding), 'series' => hash('sha256', $series->key), 'payload' => $payload, 'actual' => null, 'observedAt' => null];
    }
    public function records(Project $project): array
    {
        return array_values(array_filter($this->saved, static fn(array $r): bool => $r['workspace'] === $project->workspaceId && $r['project'] === $project->id));
    }
    public function observations(Project $project, string $key, string $binding, string $asOf): array
    {
        foreach ($this->sources[$project->id] ?? [] as $series) {
            if ($series->key === $key && hash('sha256', $series->binding) === $binding && $series->blocked === null) {
                return array_filter($series->values, static fn(string $date): bool => $date <= $asOf, ARRAY_FILTER_USE_KEY);
            }
        }
        return [];
    }
}

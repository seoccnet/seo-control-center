<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

use SeoControlCenter\Domain\Projects\Project;

interface ForecastRepository
{
    /** @return list<ForecastSeries> Bounded persisted data, never credentials or providers. */
    public function series(Project $project, string $asOf): array;
    /** @param array<string, mixed> $payload */
    public function save(Project $project, string $issued, int $horizon, ForecastSeries $series, array $payload): void;
    /** @return list<array<string, mixed>> Latest bounded immutable records. */
    public function records(Project $project): array;
    /** @return array<string, float> Actuals for one saved key and unchanged property binding. */
    public function observations(Project $project, string $key, string $binding, string $asOf): array;
    /** @return list<ForecastEvent> */
    public function events(Project $project): array;
    public function saveEvent(Project $project, ForecastEvent $event): void;
    public function excludeEvent(Project $project, string $name, string $date, string $excludedOn): void;
    public function observe(Project $project, int $id, float $actual): void;
    /** @return array{enabled: bool, lastIssued: ?string, checked: ?string} */
    public function tracking(Project $project): array;
    public function setTracking(Project $project, bool $enabled): void;
    public function checked(Project $project, string $date, bool $issued): void;
    /** @return list<array{workspace: int, project: int, publicId: string}> */
    public function dueProjects(string $date): array;
}

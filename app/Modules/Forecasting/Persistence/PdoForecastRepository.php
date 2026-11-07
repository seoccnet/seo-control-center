<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Forecasting\ForecastEngine;
use SeoControlCenter\Modules\Forecasting\ForecastEvent;
use SeoControlCenter\Modules\Forecasting\ForecastRepository;
use SeoControlCenter\Modules\Forecasting\ForecastSeries;

final readonly class PdoForecastRepository implements ForecastRepository
{
    public function __construct(private Connection $db, private SearchPerformanceReader $search, private Ga4Repository $ga4, private Clock $clock) {}

    public function series(Project $project, string $asOf): array
    {
        if (!$this->active($project)) {
            return [];
        }
        $range = DateRange::endingOn($asOf, 486);
        $searchValues = [];
        foreach ($this->search->daily($project->id, $range) as $row) {
            $searchValues[$row->date] = (float) $row->metrics->clicks;
        }
        $googleBinding = $this->googleBinding($project);
        $result = [new ForecastSeries('google:clicks', 'Google clicks · site', $googleBinding, $searchValues)];
        foreach (['query' => ['queries', 'query_id', 'search_queries', 'query'], 'page' => ['pages', 'page_id', 'pages', 'url']] as $kind => [$table, $id, $dictionary, $label]) {
            // Only observed keys, top five by saved impressions. Absence remains unknown.
            $leaders = $this->db->fetchAll('SELECT s.' . $id . ' AS series_id, d.' . $label . ' AS label FROM search_console_' . $table . ' s JOIN ' . $dictionary . ' d ON d.id = s.' . $id . ' AND d.project_id = s.project_id WHERE s.project_id = ? AND s.date BETWEEN ? AND ? GROUP BY s.' . $id . ', d.' . $label . ' ORDER BY SUM(s.impressions) DESC, s.' . $id . ' LIMIT 5', [$project->id, ForecastEngine::date($asOf, -27), $asOf]);
            foreach ($leaders as $leader) {
                $values = [];
                foreach ($this->db->fetchAll('SELECT date, SUM(clicks) AS value FROM search_console_' . $table . ' WHERE project_id = ? AND ' . $id . ' = ? AND date BETWEEN ? AND ? GROUP BY date ORDER BY date', [$project->id, (int) $leader['series_id'], $range->fromString(), $range->toString()]) as $row) {
                    $values[(string) $row['date']] = (float) $row['value'];
                }
                $result[] = new ForecastSeries('google:' . $kind . ':' . (string) $leader['series_id'], 'Google ' . $kind . ' · ' . (string) $leader['label'], $googleBinding, $values);
            }
        }
        // Other Google surfaces with clicks in the last 28 days; the engine withholds sparse ones.
        foreach (SearchType::cases() as $type) {
            $values = $this->surfaceClicks($project, $type, $range);
            $recent = array_filter($values, static fn(float $v, string $date): bool => $date >= ForecastEngine::date($asOf, -27) && $v > 0, ARRAY_FILTER_USE_BOTH);
            if ($recent !== []) {
                $result[] = new ForecastSeries('google:surface:' . $type->value, 'Google ' . $type->label() . ' clicks · site', $googleBinding, $values);
            }
        }
        $meta = $this->ga4->metadata($project->id, $range);
        $blocked = $meta === null ? 'Connect GA4 and import Organic Search history.' : ($meta['limited'] || $meta['thresholded'] ? 'GA4 history has limited or thresholded report data.' : null);
        $daily = $blocked === null ? $this->ga4->daily($project->id, $range) : [];
        $binding = 'ga4:' . ($this->ga4->propertyId($project->id) ?? '') . ':' . ($meta['currency'] ?? '') . ':' . ($meta['timezone'] ?? '');
        foreach (['sessions' => 'Organic Search sessions', 'conversions' => 'Organic Search key events'] as $metric => $label) {
            $values = [];
            foreach ($daily as $row) {
                $values[$row['date']] = (float) $row[$metric];
            }
            $result[] = new ForecastSeries('ga4:' . $metric, 'GA4 · ' . $label, $binding, $values, $blocked);
        }
        return $result;
    }

    public function save(Project $project, string $issued, int $horizon, ForecastSeries $series, array $payload): void
    {
        // INSERT IGNORE preserves the first issued prediction, including after data corrections.
        $this->db->execute('INSERT IGNORE INTO forecast_snapshots (workspace_id, project_id, issued_on, horizon, series_hash, binding_hash, model_version, payload, created_at) SELECT ?, ?, ?, ?, ?, ?, ?, ?, ? FROM projects p JOIN workspaces w ON w.id = p.workspace_id WHERE p.id = ? AND p.workspace_id = ? AND p.deleted_at IS NULL AND w.deleted_at IS NULL', [$project->workspaceId, $project->id, $issued, $horizon, hash('sha256', $series->key), hash('sha256', $series->binding), ForecastEngine::VERSION, json_encode($payload, JSON_THROW_ON_ERROR), $this->clock->now()->format('Y-m-d H:i:s'), $project->id, $project->workspaceId]);
    }

    public function records(Project $project): array
    {
        return array_map(static function (array $row): array {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $row['payload'], true, flags: JSON_THROW_ON_ERROR);
            return ['id' => (int) $row['id'], 'issued' => (string) $row['issued_on'], 'horizon' => (int) $row['horizon'], 'version' => (string) $row['model_version'], 'binding' => (string) $row['binding_hash'], 'series' => (string) $row['series_hash'], 'payload' => $payload, 'actual' => $row['actual_value'] === null ? null : (float) $row['actual_value'], 'observedAt' => $row['actual_observed_at'] === null ? null : (string) $row['actual_observed_at']];
        }, $this->db->fetchAll("SELECT f.id, f.issued_on, f.horizon, f.model_version, f.binding_hash, f.series_hash, JSON_REMOVE(f.payload, '$.points') AS payload, f.actual_value, f.actual_observed_at FROM forecast_snapshots f JOIN projects p ON p.id = f.project_id AND p.workspace_id = f.workspace_id JOIN workspaces w ON w.id = f.workspace_id WHERE f.project_id = ? AND f.workspace_id = ? AND p.deleted_at IS NULL AND w.deleted_at IS NULL ORDER BY f.issued_on DESC, f.id DESC LIMIT 5000", [$project->id, $project->workspaceId]));
    }

    private function googleBinding(Project $project): string
    {
        $link = $this->db->fetchOne('SELECT site_url, linked_at FROM project_search_console WHERE project_id = ?', [$project->id]);
        return 'google:' . $project->primaryDomain . ':' . (string) ($link['site_url'] ?? '') . ':' . (string) ($link['linked_at'] ?? '');
    }

    public function observations(Project $project, string $key, string $binding, string $asOf): array
    {
        if (!$this->active($project)) {
            return [];
        }
        $range = DateRange::endingOn($asOf, 486);
        if (str_starts_with($key, 'ga4:')) {
            $meta = $this->ga4->metadata($project->id, $range);
            $current = 'ga4:' . ($this->ga4->propertyId($project->id) ?? '') . ':' . ($meta['currency'] ?? '') . ':' . ($meta['timezone'] ?? '');
            $metric = substr($key, 4);
            if ($meta === null || $meta['limited'] || $meta['thresholded'] || hash('sha256', $current) !== $binding || !in_array($metric, ['sessions', 'conversions'], true)) {
                return [];
            }
            $values = [];
            foreach ($this->ga4->daily($project->id, $range) as $row) {
                $values[$row['date']] = (float) $row[$metric];
            }
            return $values;
        }
        if (hash('sha256', $this->googleBinding($project)) !== $binding) {
            return [];
        }
        if ($key === 'google:clicks') {
            $values = [];
            foreach ($this->search->daily($project->id, $range) as $row) {
                $values[$row->date] = (float) $row->metrics->clicks;
            }
            return $values;
        }
        if (str_starts_with($key, 'google:surface:')) {
            $type = SearchType::tryFrom(substr($key, strlen('google:surface:')));

            return $type === null ? [] : $this->surfaceClicks($project, $type, $range);
        }
        if (preg_match('/^google:(query|page):(\d+)$/D', $key, $match) !== 1) {
            return [];
        }
        [$table, $column] = $match[1] === 'query' ? ['queries', 'query_id'] : ['pages', 'page_id'];
        $values = [];
        foreach ($this->db->fetchAll('SELECT date, SUM(clicks) AS value FROM search_console_' . $table . ' WHERE project_id = ? AND ' . $column . ' = ? AND date BETWEEN ? AND ? GROUP BY date ORDER BY date', [$project->id, (int) $match[2], $range->fromString(), $range->toString()]) as $row) {
            $values[(string) $row['date']] = (float) $row['value'];
        }
        return $values;
    }

    /** @return array<string, float> date => clicks of one surface; days without a row stay missing */
    private function surfaceClicks(Project $project, SearchType $type, DateRange $range): array
    {
        $values = [];
        foreach ($this->db->fetchAll('SELECT date, clicks FROM search_console_search_types WHERE project_id = ? AND search_type = ? AND date BETWEEN ? AND ? ORDER BY date', [$project->id, $type->value, $range->fromString(), $range->toString()]) as $row) {
            $values[(string) $row['date']] = (float) $row['clicks'];
        }

        return $values;
    }

    private function active(Project $project): bool
    {
        return $this->db->fetchOne('SELECT p.id FROM projects p JOIN workspaces w ON w.id = p.workspace_id WHERE p.id = ? AND p.workspace_id = ? AND p.deleted_at IS NULL AND w.deleted_at IS NULL', [$project->id, $project->workspaceId]) !== null;
    }

    public function events(Project $project): array
    {
        if (!$this->active($project)) {
            return [];
        }
        return array_map(static fn(array $row): ForecastEvent => new ForecastEvent((string) $row['event_name'], (string) $row['event_on'], (string) $row['known_on'], $row['excluded_on'] === null ? null : (string) $row['excluded_on']), $this->db->fetchAll('SELECT event_name, event_on, known_on, excluded_on FROM forecast_calendar WHERE workspace_id = ? AND project_id = ? ORDER BY event_on DESC LIMIT 200', [$project->workspaceId, $project->id]));
    }
    public function saveEvent(Project $project, ForecastEvent $event): void
    {
        $this->db->transaction(function () use ($project, $event): void {
            $active = $this->db->fetchOne('SELECT p.id FROM projects p JOIN workspaces w ON w.id = p.workspace_id WHERE p.id = ? AND p.workspace_id = ? AND p.deleted_at IS NULL AND w.deleted_at IS NULL FOR UPDATE', [$project->id, $project->workspaceId]);
            if ($active === null) {
                return;
            }
            $count = (int) $this->db->fetchValue('SELECT COUNT(*) FROM forecast_calendar WHERE workspace_id = ? AND project_id = ?', [$project->workspaceId, $project->id]);
            if ($count >= 200) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(422, 'The calendar supports up to 200 recorded occurrences.');
            }
            $this->db->execute('INSERT IGNORE INTO forecast_calendar (workspace_id, project_id, event_name, name_hash, event_on, known_on) VALUES (?, ?, ?, ?, ?, ?)', [$project->workspaceId, $project->id, $event->name, hash('sha256', $event->name), $event->date, $event->knownOn]);
        });
    }

    public function observe(Project $project, int $id, float $actual): void
    {
        if (!is_finite($actual) || $actual < 0 || !$this->active($project)) {
            return;
        }
        $this->db->execute("UPDATE forecast_snapshots SET actual_value = ?, actual_observed_at = ? WHERE workspace_id = ? AND project_id = ? AND id = ? AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.target')) < ?", [$actual, $this->clock->now()->format('Y-m-d H:i:s'), $project->workspaceId, $project->id, $id, $this->clock->now()->format('Y-m-d')]);
    }
    public function tracking(Project $project): array
    {
        $row = $this->db->fetchOne('SELECT enabled, last_issued_on, checked_on FROM forecast_tracking WHERE workspace_id = ? AND project_id = ?', [$project->workspaceId, $project->id]);
        return ['enabled' => (bool) ($row['enabled'] ?? false), 'lastIssued' => isset($row['last_issued_on']) ? (string) $row['last_issued_on'] : null, 'checked' => isset($row['checked_on']) ? (string) $row['checked_on'] : null];
    }
    public function setTracking(Project $project, bool $enabled): void
    {
        if ($this->active($project)) {
            $this->db->execute('INSERT INTO forecast_tracking (workspace_id, project_id, enabled) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)', [$project->workspaceId, $project->id, $enabled ? 1 : 0]);
        }
    }
    public function checked(Project $project, string $date, bool $issued): void
    {
        $this->db->execute('UPDATE forecast_tracking SET checked_on = ?, last_issued_on = CASE WHEN ? = 1 THEN ? ELSE last_issued_on END WHERE workspace_id = ? AND project_id = ?', [$date, $issued ? 1 : 0, $date, $project->workspaceId, $project->id]);
    }
    public function dueProjects(string $date): array
    {
        return array_map(static fn(array $row): array => ['workspace' => (int) $row['workspace_id'], 'project' => (int) $row['id'], 'publicId' => (string) $row['public_id']], $this->db->fetchAll('SELECT p.id, p.workspace_id, p.public_id FROM forecast_tracking t JOIN projects p ON p.id = t.project_id AND p.workspace_id = t.workspace_id JOIN workspaces w ON w.id = p.workspace_id WHERE t.enabled = 1 AND (t.checked_on IS NULL OR t.checked_on < ?) AND p.deleted_at IS NULL AND w.deleted_at IS NULL ORDER BY t.checked_on, p.id LIMIT 50', [$date]));
    }
    public function excludeEvent(Project $project, string $name, string $date, string $excludedOn): void
    {
        if ($this->active($project)) {
            $this->db->execute('UPDATE forecast_calendar SET excluded_on = COALESCE(excluded_on, ?) WHERE workspace_id = ? AND project_id = ? AND name_hash = ? AND event_on = ?', [$excludedOn, $project->workspaceId, $project->id, hash('sha256', $name), $date]);
        }
    }
}

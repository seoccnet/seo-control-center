<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Modules\Local\LocalLocation;
use SeoControlCenter\Modules\Local\LocationRepository;

final readonly class PdoLocationRepository implements LocationRepository
{
    public function __construct(private Connection $db) {}
    public function grids(int $projectId, string $location): array
    {
        $branch = $this->find($projectId, $location);
        return $branch === null ? [] : array_map(static function (array $r) use ($branch): \SeoControlCenter\Modules\Local\BranchGrid {
            $positions = json_decode((string) $r['positions'], true, 512, JSON_THROW_ON_ERROR);
            $points = [];
            foreach (is_array($positions) ? $positions : [] as $index => $position) {
                if (is_int($index) && ($position === null || is_int($position))) {
                    $points[$index] = $position;
                }
            }
            return new \SeoControlCenter\Modules\Local\BranchGrid((string) $r['public_id'], (string) $r['keyword'], new \SeoControlCenter\Domain\Local\LocalGrid(0, (string) $r['checked_on'], (int) $r['size'], (int) $r['spacing'], $branch->area, $points, (string) $r['status']), is_string($r['error']) ? $r['error'] : null);
        }, $this->db->fetchAll('SELECT * FROM local_location_grids WHERE project_id = ? AND location_public_id = ? ORDER BY public_id DESC LIMIT 12', [$projectId, $location]));
    }
    public function queuedGrid(int $projectId, string $location): ?\SeoControlCenter\Modules\Local\BranchGrid
    {
        foreach ($this->grids($projectId, $location) as $grid) {
            if ($grid->grid->status === 'queued') {
                return $grid;
            }
        }
        return null;
    }
    public function createGrid(int $projectId, string $location, \SeoControlCenter\Modules\Local\BranchGrid $grid): void
    {
        $this->db->transaction(function () use ($projectId, $location, $grid): void {
            $branch = $this->db->fetchOne('SELECT public_id FROM local_locations WHERE project_id = ? AND public_id = ? FOR UPDATE', [$projectId, $location]);
            if ($branch === null) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(404, 'Branch not found.');
            }
            if ($this->db->fetchValue('SELECT public_id FROM local_location_grids WHERE project_id = ? AND location_public_id = ? AND status = \'queued\' LIMIT 1', [$projectId, $location]) !== null) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(409, 'A grid is already queued for this branch.');
            }
            $this->db->execute('INSERT INTO local_location_grids (project_id, location_public_id, public_id, keyword, checked_on, size, spacing, positions, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'queued\')', [$projectId, $location, $grid->publicId, $grid->keyword, $grid->grid->checkedOn, $grid->grid->size, $grid->grid->spacing, '{}']);
        });
    }
    public function saveGridPoint(int $projectId, string $publicId, int $index, ?int $position): void
    {
        $this->db->execute('UPDATE local_location_grids SET positions = JSON_SET(positions, ?, JSON_EXTRACT(?, \'$\')) WHERE project_id = ? AND public_id = ? AND status = \'queued\'', ['$.' . '"' . $index . '"', json_encode($position, JSON_THROW_ON_ERROR), $projectId, $publicId]);
    }
    public function finishGrid(int $projectId, string $publicId, string $status, ?string $error = null): void
    {
        $this->db->execute('UPDATE local_location_grids SET status = ?, error = ? WHERE project_id = ? AND public_id = ? AND status = \'queued\'', [$status, $error === null ? null : mb_substr($error, 0, 500), $projectId, $publicId]);
    }
    public function locations(int $projectId): array
    {
        return array_map($this->hydrate(...), $this->db->fetchAll('SELECT * FROM local_locations WHERE project_id = ? ORDER BY name, public_id', [$projectId]));
    }
    public function find(int $projectId, string $publicId): ?LocalLocation
    {
        $row = $this->db->fetchOne('SELECT * FROM local_locations WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);
        return $row === null ? null : $this->hydrate($row);
    }
    public function save(LocalLocation $location): void
    {
        $this->db->execute('INSERT INTO local_locations (project_id, public_id, name, cid, latitude, longitude, zoom, language, keywords, weekly) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), cid = VALUES(cid), latitude = VALUES(latitude), longitude = VALUES(longitude), zoom = VALUES(zoom), language = VALUES(language), keywords = VALUES(keywords), weekly = VALUES(weekly)', [$location->projectId, $location->publicId, $location->name, $location->cid, $location->area->latitude, $location->area->longitude, $location->area->zoom, $location->language, json_encode($location->keywords, JSON_THROW_ON_ERROR), $location->weekly ? 1 : 0]);
    }
    public function remove(int $projectId, string $publicId): void
    {
        $this->db->transaction(function () use ($projectId, $publicId): void {
            $this->db->execute('DELETE FROM local_reviews WHERE project_id = ? AND location_key = ?', [$projectId, $publicId]);
            $this->db->execute('DELETE FROM local_locations WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);
        });
    }
    public function ranking(int $projectId, string $publicId, string $date): array
    {
        return self::decode($this->db->fetchValue('SELECT result FROM local_location_checks WHERE project_id = ? AND location_public_id = ? AND date = ?', [$projectId, $publicId, $date]));
    }
    public function saveRanking(int $projectId, string $publicId, string $date, array $result): void
    {
        $this->db->execute('INSERT INTO local_location_checks (project_id, location_public_id, date, result) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE result = VALUES(result)', [$projectId, $publicId, $date, json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }
    public function history(int $projectId, string $publicId): array
    {
        return array_map(static fn(array $r): array => ['date' => (string) $r['date'], 'result' => self::decode($r['result'])], $this->db->fetchAll('SELECT date, result FROM local_location_checks WHERE project_id = ? AND location_public_id = ? ORDER BY date DESC LIMIT 12', [$projectId, $publicId]));
    }
    public function markChecked(int $projectId, string $publicId, string $date): void
    {
        $this->db->execute('UPDATE local_locations SET checked_on = ? WHERE project_id = ? AND public_id = ?', [$date, $projectId, $publicId]);
    }
    public function due(string $before): array
    {
        return array_map(static fn(array $r): array => ['project_id' => (int) $r['id'], 'workspace_id' => (int) $r['workspace_id'], 'public_id' => (string) $r['public_id'], 'location' => (string) $r['location']], $this->db->fetchAll('SELECT p.id, p.workspace_id, p.public_id, l.public_id AS location FROM local_locations l JOIN projects p ON p.id = l.project_id JOIN workspaces w ON w.id = p.workspace_id WHERE l.weekly = 1 AND (l.checked_on IS NULL OR l.checked_on <= ?) AND p.deleted_at IS NULL AND w.deleted_at IS NULL', [$before]));
    }
    public function reviews(int $projectId, string $location): array
    {
        return self::decode($this->db->fetchValue('SELECT state FROM local_reviews WHERE project_id = ? AND location_key = ?', [$projectId, $location]));
    }
    public function saveReviews(int $projectId, string $location, array $state): void
    {
        $this->db->execute('INSERT INTO local_reviews (project_id, location_key, state) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state = VALUES(state)', [$projectId, $location, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
    }
    /** @param array<string, mixed> $r */
    private function hydrate(array $r): LocalLocation
    {
        $keywords = json_decode((string) $r['keywords'], true, 512, JSON_THROW_ON_ERROR);
        return new LocalLocation((string) $r['public_id'], (int) $r['project_id'], (string) $r['name'], (string) $r['cid'], new LocalArea((float) $r['latitude'], (float) $r['longitude'], (int) $r['zoom']), (string) $r['language'], array_values(array_filter(is_array($keywords) ? $keywords : [], 'is_string')), (bool) $r['weekly'], is_string($r['checked_on']) ? $r['checked_on'] : null);
    }
    /** @return array<string, mixed> */
    private static function decode(mixed $value): array
    {
        $data = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : [];
        return is_array($data) ? $data : [];
    }
}

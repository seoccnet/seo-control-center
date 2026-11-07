<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Research\AiAnswer;
use SeoControlCenter\Modules\Research\BrandCheck;
use SeoControlCenter\Modules\Research\BrandMentionRepository;
use SeoControlCenter\Modules\Research\BrandSettings;

final readonly class PdoBrandMentionRepository implements BrandMentionRepository
{
    public function __construct(private Connection $db, private Clock $clock) {}
    public function settings(Project $project): ?BrandSettings
    {
        $r = $this->db->fetchOne('SELECT * FROM brand_mention_settings WHERE workspace_id = ? AND project_id = ?', [$project->workspaceId, $project->id]);
        return $r === null ? null : new BrandSettings((string) $r['brand_name'], new KeywordLocale((int) $r['location_code'], (string) $r['language_code']), (bool) $r['weekly'], (int) $r['configured_by']);
    }
    public function save(Project $project, BrandSettings $settings): void
    {
        $previous = $this->settings($project);
        $this->db->execute('INSERT INTO brand_mention_settings (project_id, workspace_id, brand_name, location_code, language_code, weekly, configured_by, updated_at) SELECT id, workspace_id, ?, ?, ?, ?, ?, ? FROM projects WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL ON DUPLICATE KEY UPDATE brand_name = VALUES(brand_name), location_code = VALUES(location_code), language_code = VALUES(language_code), weekly = VALUES(weekly), configured_by = VALUES(configured_by), updated_at = VALUES(updated_at)', [$settings->name, $settings->locale->locationCode, $settings->locale->languageCode, (int) $settings->weekly, $settings->userId, $this->now(), $project->id, $project->workspaceId]);
        if ($previous === null || $previous->key($project) !== $settings->key($project)) {
            $this->db->execute('UPDATE brand_mention_settings SET attempted_at = NULL WHERE workspace_id = ? AND project_id = ?', [$project->workspaceId, $project->id]);
        }
    }
    public function due(string $before): array
    {
        return array_map(static fn(array $r): array => ['workspaceId' => (int) $r['workspace_id'], 'projectPublicId' => (string) $r['public_id']], $this->db->fetchAll('SELECT s.workspace_id, p.public_id FROM brand_mention_settings s JOIN projects p ON p.id = s.project_id AND p.workspace_id = s.workspace_id JOIN workspaces w ON w.id = s.workspace_id WHERE s.weekly = 1 AND (s.attempted_at IS NULL OR s.attempted_at <= ?) AND p.deleted_at IS NULL AND w.deleted_at IS NULL', [$before]));
    }
    public function create(Project $project, BrandSettings $settings, int $userId, bool $scheduled): BrandCheck
    {
        return $this->db->transaction(function () use ($project, $settings, $userId, $scheduled): BrandCheck {
            if ($this->db->fetchOne('SELECT project_id FROM brand_mention_settings WHERE workspace_id = ? AND project_id = ? FOR UPDATE', [$project->workspaceId, $project->id]) === null) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(409, 'Save brand settings first.');
            }
            if ($this->db->fetchValue("SELECT public_id FROM brand_mention_checks WHERE workspace_id = ? AND project_id = ? AND config_key = ? AND status IN ('queued', 'processing') AND updated_at > ? LIMIT 1", [$project->workspaceId, $project->id, $settings->key($project), $this->clock->now()->modify('-24 hours')->format('Y-m-d H:i:s')]) !== null) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(409, 'A brand check is already pending. After an interrupted request, wait 24 hours before a new paid check.');
            }
            $id = Uuid::v7();
            // A fresh explicit request supersedes abandoned work, including a late queued delivery.
            $this->db->execute("UPDATE brand_mention_checks SET status = 'failed', error = 'Superseded after 24 hours by a new check. Review spending for any request already sent.', updated_at = ? WHERE workspace_id = ? AND project_id = ? AND config_key = ? AND status IN ('queued', 'processing')", [$this->now(), $project->workspaceId, $project->id, $settings->key($project)]);
            $this->db->execute("INSERT INTO brand_mention_checks (public_id, project_id, workspace_id, config_key, status, metrics, answers, requested_by, scheduled, created_at, updated_at) VALUES (?, ?, ?, ?, 'queued', '{}', '{}', ?, ?, ?, ?)", [$id, $project->id, $project->workspaceId, $settings->key($project), $userId, (int) $scheduled, $this->now(), $this->now()]);
            $this->db->execute('UPDATE brand_mention_settings SET attempted_at = ? WHERE workspace_id = ? AND project_id = ?', [$this->now(), $project->workspaceId, $project->id]);
            return $this->find($project, $id) ?? throw new \RuntimeException('Brand check was not stored.');
        });
    }
    public function find(Project $project, string $publicId): ?BrandCheck
    {
        $r = $this->db->fetchOne('SELECT * FROM brand_mention_checks WHERE workspace_id = ? AND project_id = ? AND public_id = ?', [$project->workspaceId, $project->id, $publicId]);
        return $r === null ? null : $this->hydrate($r);
    }
    public function history(Project $project, string $key, int $limit): array
    {
        return array_map($this->hydrate(...), $this->db->fetchAll('SELECT * FROM brand_mention_checks WHERE workspace_id = ? AND project_id = ? AND config_key = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)), [$project->workspaceId, $project->id, $key]));
    }
    public function claim(Project $project, BrandCheck $check): bool
    {
        return $this->db->execute("UPDATE brand_mention_checks SET status = 'processing', updated_at = ? WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND status = 'queued' AND step = ?", [$this->now(), $project->workspaceId, $project->id, $check->publicId, $check->step]) === 1;
    }
    public function advance(Project $project, BrandCheck $check, array $metrics, array $answers): void
    {
        $json = array_map(static fn(array $items): array => array_map(static fn(AiAnswer $a): array => $a->toArray(), $items), $answers);
        $this->db->execute('UPDATE brand_mention_checks SET status = ?, step = ?, metrics = ?, answers = ?, updated_at = ? WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND step = ? AND status = ?', [$check->step === 3 ? 'completed' : 'queued', $check->step + 1, json_encode($metrics, JSON_THROW_ON_ERROR), json_encode($json, JSON_THROW_ON_ERROR), $this->now(), $project->workspaceId, $project->id, $check->publicId, $check->step, 'processing']);
    }
    public function fail(Project $project, string $publicId, string $message): void
    {
        $this->db->execute("UPDATE brand_mention_checks SET status = 'failed', error = ?, updated_at = ? WHERE workspace_id = ? AND project_id = ? AND public_id = ? AND status IN ('queued', 'processing')", [mb_substr($message, 0, 500), $this->now(), $project->workspaceId, $project->id, $publicId]);
    }
    /** @param array<string, mixed> $r */
    private function hydrate(array $r): BrandCheck
    {
        /** @var array<string, array{mentions: int, ai_search_volume: ?int}> $metrics */
        $metrics = json_decode((string) $r['metrics'], true, flags: JSON_THROW_ON_ERROR);
        /** @var array<string, list<array<mixed>>> $raw */
        $raw = json_decode((string) $r['answers'], true, flags: JSON_THROW_ON_ERROR);
        $answers = array_map(static fn(array $items): array => array_values(array_filter(array_map(AiAnswer::fromArray(...), $items))), $raw);
        return new BrandCheck((string) $r['public_id'], (string) $r['config_key'], (string) $r['status'], (int) $r['step'], $metrics, $answers, $r['error'] === null ? null : (string) $r['error'], (string) $r['created_at'], (string) $r['updated_at'], (int) $r['requested_by'], (bool) $r['scheduled']);
    }
    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}

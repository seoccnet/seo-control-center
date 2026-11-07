<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\BusinessContextRepository;

final readonly class PdoBusinessContextRepository implements BusinessContextRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function find(int $projectId): ?BusinessContext
    {
        $row = $this->db->fetchOne('SELECT business_model, goal, description, audience, offerings, exclusions, markets, max_difficulty, max_strength, updated_at FROM project_business_profiles WHERE project_id = ?', [$projectId]);
        if ($row === null) {
            return null;
        }

        return new BusinessContext(
            (string) $row['business_model'],
            (string) $row['goal'],
            (string) $row['description'],
            (string) $row['audience'],
            BusinessContext::terms(json_decode((string) $row['offerings'], true)),
            BusinessContext::terms(json_decode((string) $row['exclusions'], true)),
            $row['max_difficulty'] !== null ? (int) $row['max_difficulty'] : null,
            $row['max_strength'] !== null ? (int) $row['max_strength'] : null,
            (string) $row['updated_at'],
            BusinessContext::markets($row['markets'] !== null ? json_decode((string) $row['markets'], true) : []),
        );
    }

    public function save(int $projectId, BusinessContext $context, int $userId): void
    {
        $this->db->execute(
            'INSERT INTO project_business_profiles (project_id, business_model, goal, description, audience, offerings, exclusions, markets, max_difficulty, max_strength, updated_by, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE business_model = VALUES(business_model), goal = VALUES(goal), description = VALUES(description), audience = VALUES(audience),
                 offerings = VALUES(offerings), exclusions = VALUES(exclusions), markets = VALUES(markets), max_difficulty = VALUES(max_difficulty), max_strength = VALUES(max_strength),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [
                $projectId,
                $context->model,
                $context->goal,
                $context->description,
                $context->audience,
                json_encode($context->offerings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                json_encode($context->exclusions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                $context->markets === [] ? null : json_encode($context->markets, JSON_THROW_ON_ERROR),
                $context->maxDifficulty,
                $context->maxStrength,
                $userId,
                $this->now(),
            ],
        );
    }

    public function delete(int $projectId): void
    {
        $this->db->execute('DELETE FROM project_business_profiles WHERE project_id = ?', [$projectId]);
    }

    public function ratings(int $projectId): array
    {
        $ratings = [];
        foreach ($this->db->fetchAll('SELECT keyword, score FROM opportunity_keyword_ratings WHERE project_id = ? ORDER BY keyword LIMIT 5000', [$projectId]) as $row) {
            $ratings[Keyword::normalize((string) $row['keyword'])] = (int) $row['score'];
        }

        return $ratings;
    }

    public function rate(int $projectId, string $keyword, ?int $score, int $userId): void
    {
        $keyword = mb_substr(Keyword::normalize($keyword), 0, 255);
        $hash = Keyword::hash($keyword);
        if ($score === null) {
            $this->db->execute('DELETE FROM opportunity_keyword_ratings WHERE project_id = ? AND keyword_hash = ?', [$projectId, $hash]);

            return;
        }
        $this->db->execute(
            'INSERT INTO opportunity_keyword_ratings (project_id, keyword_hash, keyword, score, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE keyword = VALUES(keyword), score = VALUES(score), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$projectId, $hash, $keyword, max(0, min(3, $score)), $userId, $this->now()],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}

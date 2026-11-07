<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use SeoControlCenter\Domain\Research\AiAnswer;

final readonly class BrandCheck
{
    /**
     * @param array<string, array{mentions: int, ai_search_volume: ?int}> $metrics
     * @param array<string, list<AiAnswer>> $answers */
    public function __construct(public string $publicId, public string $key, public string $status, public int $step, public array $metrics, public array $answers, public ?string $error, public string $createdAt, public string $updatedAt, public int $userId, public bool $scheduled) {}
    /**
     * @return array<string, mixed> */
    public function present(bool $includeAnswers = true): array
    {
        return ['id' => $this->publicId, 'status' => $this->status, 'checked_at' => $this->updatedAt, 'metrics' => $this->metrics, 'answers' => $includeAnswers ? array_map(static fn(array $answers): array => array_map(static fn(AiAnswer $a): array => ['platform' => $a->platform, 'question' => $a->question, 'answer_excerpt' => $a->excerpt, 'ai_search_volume' => $a->aiSearchVolume, 'source_rank' => $a->sourceRank, 'source_url' => $a->sourceUrl, 'other_sources' => $a->sources, 'last_seen' => $a->lastSeen], $answers), $this->answers) : [], 'error' => $this->error];
    }
}

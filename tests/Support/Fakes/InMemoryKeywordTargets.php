<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Modules\Rankings\KeywordTargetRepository;

/**
 * Keyword target pages in memory, on top of InMemoryKeywords.
 */
final class InMemoryKeywordTargets implements KeywordTargetRepository
{
    /** @var array<int, array<string, string>> project ID => keyword public ID => URL */
    public array $targets = [];

    public function __construct(private readonly InMemoryKeywords $keywords) {}

    public function targets(int $projectId): array
    {
        return $this->targets[$projectId] ?? [];
    }

    public function set(int $projectId, array $targets, int $userId): int
    {
        $known = array_fill_keys(array_column($this->keywords->keywords[$projectId] ?? [], 'public_id'), true);
        $changed = 0;
        foreach ($targets as $publicId => $url) {
            $publicId = (string) $publicId;
            if (!isset($known[$publicId])) {
                continue;
            }
            if ($url === null) {
                if (isset($this->targets[$projectId][$publicId])) {
                    unset($this->targets[$projectId][$publicId]);
                    ++$changed;
                }
                continue;
            }
            $this->targets[$projectId][$publicId] = $url;
            ++$changed;
        }

        return $changed;
    }
}

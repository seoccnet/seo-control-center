<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings;

use SeoControlCenter\Domain\Keywords\KeywordLocale;

/** Writes imported ranking history (1.3.0). */
interface RankingImportStore
{
    /**
     * Tracked keyword IDs of the project in this market.
     *
     * @param list<string> $keywords normalized
     * @return array<string, int> normalized keyword => project keyword id
     */
    public function keywordIds(int $projectId, array $keywords, KeywordLocale $locale): array;

    /**
     * Inserts positions for days not checked yet; existing days (checked or imported before) are kept.
     *
     * @param list<array{keywordId: int, device: string, date: string, position: ?int, url: ?string}> $rows
     * @return int rows inserted
     */
    public function insert(int $projectId, array $rows): int;
}

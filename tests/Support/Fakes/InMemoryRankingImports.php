<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\Rankings\RankingImportStore;

/** Imported ranking history into the in-memory keywords and rankings; remembers which snapshots were imported. */
final class InMemoryRankingImports implements RankingImportStore
{
    /** @var array<string, true> snapshot keys written by imports */
    public array $imported = [];

    public function __construct(private readonly InMemoryKeywords $keywords, private readonly InMemoryRankings $rankings) {}

    public function keywordIds(int $projectId, array $keywords, KeywordLocale $locale): array
    {
        $out = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $row) {
            if ($row['locale']->equals($locale) && in_array($row['keyword'], $keywords, true)) {
                $out[$row['keyword']] = $row['id'];
            }
        }

        return $out;
    }

    public function insert(int $projectId, array $rows): int
    {
        $inserted = 0;
        foreach ($rows as $r) {
            $key = $r['keywordId'] . ':' . $r['device'] . ':' . $r['date'];
            if (isset($this->rankings->snapshots[$key])) {
                continue;
            }
            $this->rankings->snapshots[$key] = new RankSnapshot($r['keywordId'], $projectId, SerpDevice::from($r['device']), $r['date'], $r['position'], null, $r['url'], [], []);
            $this->imported[$key] = true;
            $this->rankings->imported[$key] = true;
            ++$inserted;
        }

        return $inserted;
    }
}

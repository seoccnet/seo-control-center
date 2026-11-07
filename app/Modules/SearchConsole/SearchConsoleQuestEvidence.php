<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Domain\Quests\QuestEvidenceSource;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;

/** Quest Log evidence from imported Search Console data (ADR-035). Reads stored data only. */
final readonly class SearchConsoleQuestEvidence implements QuestEvidenceSource
{
    /** Days of history needed to compare four full weeks. */
    private const int HISTORY_DAYS = 28;

    public function __construct(private SearchPerformanceReader $performance) {}

    public function signals(): array
    {
        return ['search.data', 'search.history-28'];
    }

    public function has(string $signal, EvidenceScope $scope): bool
    {
        foreach ($scope->projectIds as $projectId) {
            $span = $this->performance->dataSpan($projectId);
            if ($span === null) {
                continue;
            }
            if ($signal === 'search.data') {
                return true;
            }
            if ($signal === 'search.history-28' && (int) new \DateTimeImmutable($span['first'])->diff(new \DateTimeImmutable($span['last']))->days >= self::HISTORY_DAYS - 1) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/** Evidence owned by one module, answering a fixed set of signals. */
interface QuestEvidenceSource extends QuestEvidence
{
    /** @return list<string> */
    public function signals(): array;
}

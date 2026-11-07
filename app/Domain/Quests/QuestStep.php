<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

final readonly class QuestStep
{
    /**
     * @param list<string> $signals evidence signals; any one of them completes the step
     * @param ?string $addedOn UTC date a step was added to an existing quest: a quest completed
     *                         before it reopens until this step is done (0.32.1)
     */
    public function __construct(
        public string $key,
        public string $label,
        public StepKind $kind,
        public array $signals = [],
        public ?ShowMe $showMe = null,
        public ?string $addedOn = null,
    ) {}

    /** Whether a quest completed at $completedAt already covered this step. */
    public function coveredBy(string $completedAt): bool
    {
        return $this->addedOn === null || $completedAt >= $this->addedOn;
    }
}

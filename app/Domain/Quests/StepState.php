<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

final readonly class StepState
{
    public function __construct(
        public QuestStep $step,
        public bool $done,
    ) {}

    public function waiting(): bool
    {
        return !$this->done && $this->step->kind === StepKind::Wait;
    }
}

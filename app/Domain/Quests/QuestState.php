<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/** A visible quest with its progress for one user (and, for Foundations, one project). */
final readonly class QuestState
{
    public const string COMPLETED = 'Completed';
    public const string SKIPPED = 'Skipped';
    public const string WAITING = 'Waiting';
    public const string IN_PROGRESS = 'In progress';
    public const string NOT_STARTED = 'Not started';
    public const string CHECKED_IN = 'Checked in this week';

    /**
     * @param list<StepState> $steps
     * @param ?SetupStatus $setup live status for Foundations quests
     * @param ?string $lastCheckIn Weekly Check-in: ISO week key of the latest check-in
     */
    public function __construct(
        public Quest $quest,
        public array $steps,
        public ?SetupStatus $setup = null,
        public ?string $completedAt = null,
        public ?string $skippedAt = null,
        public ?string $lastCheckIn = null,
    ) {}

    public function done(): bool
    {
        return $this->setup !== null ? $this->setup->ready() : $this->completedAt !== null;
    }

    public function skipped(): bool
    {
        return $this->setup === null && !$this->done() && $this->skippedAt !== null;
    }

    /** Done or skipped: counts toward chapter progress. */
    public function resolved(): bool
    {
        return $this->done() || $this->skipped();
    }

    /** Foundations step this user can't perform: "Handled by your administrator". */
    public function delegated(): bool
    {
        return $this->setup !== null && !$this->setup->canAct;
    }

    public function nextStep(): ?StepState
    {
        foreach ($this->steps as $step) {
            if (!$step->done) {
                return $step;
            }
        }

        return null;
    }

    public function status(): string
    {
        if ($this->setup !== null) {
            return $this->setup->status;
        }
        if ($this->quest->weekly) {
            return $this->steps !== [] && $this->steps[0]->done ? self::CHECKED_IN : self::NOT_STARTED;
        }
        if ($this->done()) {
            return self::COMPLETED;
        }
        if ($this->skipped()) {
            return self::SKIPPED;
        }
        if ($this->nextStep()?->waiting() === true) {
            return self::WAITING;
        }

        return array_filter($this->steps, static fn(StepState $s): bool => $s->done) !== [] ? self::IN_PROGRESS : self::NOT_STARTED;
    }
}

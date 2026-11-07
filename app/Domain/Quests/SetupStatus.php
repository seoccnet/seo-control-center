<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/**
 * Live status of one Foundations setup step for one project. Unlike learning
 * quests, setup can go backwards (Google disconnected, cron stopped, budget
 * used up), so it is always read from current settings and never stored.
 */
final readonly class SetupStatus
{
    public const string READY = 'Ready';
    public const string NEEDS_SETUP = 'Needs setup';
    public const string NEEDS_ATTENTION = 'Needs attention';
    public const string WAITING = 'Waiting for first results';
    public const string OPTIONAL = 'Optional';

    /**
     * @param bool $canAct whether this user can perform the step; otherwise it is
     *                     "Handled by your administrator" and not counted in their progress
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $status,
        public ?string $href,
        public string $action,
        public bool $optional,
        public ?string $hint,
        public bool $canAct,
    ) {}

    public function ready(): bool
    {
        return $this->status === self::READY;
    }

    public function needsAttention(): bool
    {
        return $this->status === self::NEEDS_ATTENTION;
    }

    /** Counted in this user's Foundations progress. */
    public function counted(): bool
    {
        return !$this->optional && $this->canAct;
    }
}

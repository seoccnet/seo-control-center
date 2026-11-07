<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;

/**
 * One quest from the code catalogue (QuestCatalogue). Definitions live in
 * code; only progress is stored (ADR-035).
 */
final readonly class Quest
{
    /**
     * @param list<string> $learn "You will learn": 2–3 concrete skills
     * @param list<QuestStep> $steps
     * @param list<string> $tips pro tips
     * @param list<string> $mistakes common mistakes
     * @param string $guide guide chapter id from resources/guide/catalog.json, e.g. "19"
     * @param list<Feature> $features all must be enabled, otherwise the quest is left out
     * @param bool $live Foundations setup step: status is read live and can go backwards
     * @param bool $paid involves paid provider work; the quest says so and shows the budget first
     * @param bool $assistant shown only when the AI assistant is available to the user
     * @param bool $weekly the recurring Weekly Check-in; never counted in progress
     */
    public function __construct(
        public string $key,
        public int $chapter,
        public int $order,
        public string $title,
        public string $story,
        public array $learn,
        public array $steps,
        public array $tips,
        public array $mistakes,
        public string $guide,
        public int $minutes,
        public array $features = [],
        public ?Permission $permission = null,
        public PermissionScope $scope = PermissionScope::Project,
        public QuestOwner $owner = QuestOwner::User,
        public bool $live = false,
        public bool $paid = false,
        public bool $assistant = false,
        public bool $weekly = false,
    ) {}

    public function step(string $key): ?QuestStep
    {
        foreach ($this->steps as $step) {
            if ($step->key === $key) {
                return $step;
            }
        }

        return null;
    }

    /** Counted in chapter and campaign progress. */
    public function counted(): bool
    {
        return !$this->weekly;
    }
}

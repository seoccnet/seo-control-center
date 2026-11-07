<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/** Per user and workspace Quest Log preferences. */
final readonly class QuestProfile
{
    /**
     * @param list<string>|null $seenQuests quest keys visible on the last view; null before the first view
     */
    public function __construct(
        public bool $hidden = false,
        public ?array $seenQuests = null,
        public ?string $checkedAt = null,
    ) {}
}

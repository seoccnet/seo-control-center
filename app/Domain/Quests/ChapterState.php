<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

final readonly class ChapterState
{
    /**
     * @param list<QuestState> $quests visible quests only
     * @param int $done resolved quests (Foundations: ready steps this user counts)
     * @param int $total counted visible quests, so every plan can reach 100 %
     * @param ?string $earned when the chapter's rank was earned; kept even if new quests appear
     * @param ?string $next Foundations: key of the first required step that isn't ready
     */
    public function __construct(
        public Chapter $chapter,
        public array $quests,
        public int $done,
        public int $total,
        public ?string $earned,
        public bool $needsAttention = false,
        public ?string $next = null,
    ) {}

    public function complete(): bool
    {
        return $this->total > 0 && $this->done >= $this->total;
    }

    public function percent(): int
    {
        return $this->total > 0 ? (int) floor($this->done * 100 / $this->total) : 0;
    }

    public function quest(string $key): ?QuestState
    {
        foreach ($this->quests as $quest) {
            if ($quest->quest->key === $key) {
                return $quest;
            }
        }

        return null;
    }
}

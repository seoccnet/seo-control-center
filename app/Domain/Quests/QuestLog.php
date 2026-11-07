<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

final readonly class QuestLog
{
    /**
     * @param list<ChapterState> $chapters
     * @param list<Quest> $newQuests visible since the last view (e.g. after a feature was turned on)
     * @param list<Chapter> $announce ranks earned since the last view, for a subtle toast
     */
    public function __construct(
        public array $chapters,
        public ?QuestState $current,
        public array $newQuests,
        public bool $hidden,
        public array $announce,
    ) {}

    public function chapter(int $number): ?ChapterState
    {
        foreach ($this->chapters as $chapter) {
            if ($chapter->chapter->number === $number) {
                return $chapter;
            }
        }

        return null;
    }

    public function quest(string $key): ?QuestState
    {
        foreach ($this->chapters as $chapter) {
            if (($quest = $chapter->quest($key)) !== null) {
                return $quest;
            }
        }

        return null;
    }

    public function foundations(): ChapterState
    {
        return $this->chapter(1) ?? throw new \LogicException('Foundations chapter missing.');
    }

    /** A setup step went backwards or needs action: shown on Chapter 1 and the widget. */
    public function needsAttention(): bool
    {
        return $this->foundations()->needsAttention;
    }

    public function done(): int
    {
        return array_sum(array_map(static fn(ChapterState $c): int => $c->done, $this->chapters));
    }

    public function total(): int
    {
        return array_sum(array_map(static fn(ChapterState $c): int => $c->total, $this->chapters));
    }

    public function percent(): int
    {
        return $this->total() > 0 ? (int) floor($this->done() * 100 / $this->total()) : 0;
    }
}

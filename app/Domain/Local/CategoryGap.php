<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Local;

use Closure;

/**
 * The primary category of the businesses in the local packs against the
 * profile's category (0.46.0). The primary category is one of Google's
 * strongest local relevance signals: when most businesses in the top 3
 * share a category the profile does not have, that is usually the gap.
 */
final readonly class CategoryGap
{
    /** Leader appearances with a category needed before a gap is reported. */
    public const int MIN_LEADERS = 3;

    /**
     * @param array<string, int> $categories category => appearances in the top 3, most first
     */
    public function __construct(
        public ?string $own,
        public array $categories,
        public int $total,
    ) {}

    /**
     * @param list<list<LocalResult>> $tops the Maps results per local keyword
     * @param Closure(LocalResult): bool $isOwn whether a result is the project's business
     */
    public static function from(?BusinessProfile $profile, array $tops, Closure $isOwn): self
    {
        $categories = [];
        $names = [];
        foreach ($tops as $top) {
            foreach ($top as $r) {
                if ($r->position > 3 || $r->category === null || trim($r->category) === '' || $isOwn($r)) {
                    continue;
                }
                $key = mb_strtolower(trim($r->category));
                $names[$key] ??= trim($r->category);
                $categories[$key] = ($categories[$key] ?? 0) + 1;
            }
        }
        arsort($categories);
        $named = [];
        foreach ($categories as $key => $n) {
            $named[$names[$key]] = $n;
        }

        return new self($profile?->category !== null && trim($profile->category) !== '' ? trim($profile->category) : null, $named, array_sum($categories));
    }

    public function leading(): ?string
    {
        return array_key_first($this->categories);
    }

    /** How many leader appearances have the profile's own category. */
    public function ownCount(): int
    {
        foreach ($this->categories as $name => $n) {
            if ($this->own !== null && mb_strtolower($name) === mb_strtolower($this->own)) {
                return $n;
            }
        }
        return 0;
    }

    /**
     * A gap: enough leaders, the leading category holds at least half of
     * them, and the profile has a different category that at most a
     * quarter of the leaders share.
     */
    public function hasGap(): bool
    {
        $leading = $this->leading();
        if ($this->own === null || $leading === null || $this->total < self::MIN_LEADERS) {
            return false;
        }

        return mb_strtolower($leading) !== mb_strtolower($this->own)
            && $this->categories[$leading] * 2 >= $this->total
            && $this->ownCount() * 4 <= $this->total;
    }
}

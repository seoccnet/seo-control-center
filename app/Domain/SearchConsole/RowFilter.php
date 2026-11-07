<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * Criteria for a filtered query or page list: text the key must contain
 * (or must not contain), minimum volume, CTR and position bounds, the sort
 * and whether to compare with the previous period of the same length.
 * Metric bounds apply to the current period. CTR is a fraction (0.02 = 2 %).
 */
final readonly class RowFilter
{
    public const array SORTS = ['clicks', 'impressions', 'ctr', 'position', 'clicks_change', 'impressions_change', 'position_change'];
    public const array PRESENCE = ['any', 'new', 'lost'];
    public const int MAX_WORDS = 20;
    public const int MAX_TERMS = 10;
    public const int MAX_TERM_LENGTH = 100;

    /** @var list<string> */
    public array $contains;
    /** @var list<string> */
    public array $exclude;
    public bool $compare;

    /**
     * @param list<string> $contains
     * @param list<string> $exclude
     */
    public function __construct(
        array $contains = [],
        public bool $containsAll = false,
        array $exclude = [],
        public int $minImpressions = 0,
        public int $minClicks = 0,
        public ?float $minCtr = null,
        public ?float $maxCtr = null,
        public ?float $minPosition = null,
        public ?float $maxPosition = null,
        public string $sort = 'clicks',
        public bool $descending = true,
        bool $compare = false,
        public int $minWords = 0,
        public string $presence = 'any',
    ) {
        $this->contains = self::terms($contains);
        $this->exclude = self::terms($exclude);
        if (!in_array($sort, self::SORTS, true)) {
            throw new \InvalidArgumentException('Unknown sort.');
        }
        if ($minImpressions < 0 || $minClicks < 0) {
            throw new \InvalidArgumentException('Minimums cannot be negative.');
        }
        foreach ([$minCtr, $maxCtr] as $ctr) {
            if ($ctr !== null && ($ctr < 0.0 || $ctr > 1.0)) {
                throw new \InvalidArgumentException('CTR bounds are fractions from 0 to 1.');
            }
        }
        foreach ([$minPosition, $maxPosition] as $position) {
            if ($position !== null && ($position < 1.0 || $position > 1000.0)) {
                throw new \InvalidArgumentException('Position bounds are from 1 to 1000.');
            }
        }
        if ($minWords < 0 || $minWords > self::MAX_WORDS) {
            throw new \InvalidArgumentException('Minimum words are from 0 to ' . self::MAX_WORDS . '.');
        }
        if (!in_array($presence, self::PRESENCE, true)) {
            throw new \InvalidArgumentException('Unknown presence.');
        }
        // Sorting by change and new or lost rows need the previous period.
        $this->compare = $compare || $this->sortsByChange() || $presence !== 'any';
    }

    public function sortsByChange(): bool
    {
        return str_ends_with($this->sort, '_change');
    }

    /**
     * Whether a row passes every criterion (presence, text, words and metric bounds).
     */
    public function matches(FilteredRow $row): bool
    {
        $previous = $row->previous ?? Metrics::zero();
        $present = match ($this->presence) {
            'new' => $row->current->impressions > 0 && $previous->impressions === 0,
            'lost' => $row->current->impressions === 0 && $previous->impressions > 0,
            default => true,
        };

        return $present && $this->matchesKey($row->key) && $this->matchesMetrics($row->current);
    }

    public function matchesKey(string $key): bool
    {
        if ($this->minWords > 1 && self::words($key) < $this->minWords) {
            return false;
        }
        $key = mb_strtolower($key);
        foreach ($this->exclude as $term) {
            if (str_contains($key, $term)) {
                return false;
            }
        }
        if ($this->contains === []) {
            return true;
        }
        $found = array_filter($this->contains, static fn(string $term): bool => str_contains($key, $term));

        return $this->containsAll ? count($found) === count($this->contains) : $found !== [];
    }

    /**
     * Whether the current-period metrics pass the bounds. Rows without
     * impressions have no CTR or position and fail those bounds.
     */
    public function matchesMetrics(Metrics $m): bool
    {
        if ($m->impressions < $this->minImpressions || $m->clicks < $this->minClicks) {
            return false;
        }
        if (($this->minCtr !== null || $this->maxCtr !== null || $this->minPosition !== null || $this->maxPosition !== null) && $m->impressions === 0) {
            return false;
        }

        return ($this->minCtr === null || $m->ctr() >= $this->minCtr)
            && ($this->maxCtr === null || $m->ctr() <= $this->maxCtr)
            && ($this->minPosition === null || $m->position >= $this->minPosition)
            && ($this->maxPosition === null || $m->position <= $this->maxPosition);
    }

    /**
     * The value a row is sorted by (larger first when descending).
     */
    public function sortValue(FilteredRow $row): float
    {
        $c = $row->current;
        $p = $row->previous ?? Metrics::zero();

        return match ($this->sort) {
            'clicks' => (float) $c->clicks,
            'impressions' => (float) $c->impressions,
            'ctr' => $c->ctr(),
            'position' => $c->impressions > 0 ? $c->position : 1000.0,
            'clicks_change' => (float) ($c->clicks - $p->clicks),
            'impressions_change' => (float) ($c->impressions - $p->impressions),
            'position_change' => $c->impressions > 0 && $p->impressions > 0 ? $c->position - $p->position : 0.0,
            default => 0.0,
        };
    }

    /**
     * Sort order: the chosen value, then impressions, then the key.
     */
    public function compare(FilteredRow $a, FilteredRow $b): int
    {
        $order = $this->descending ? $this->sortValue($b) <=> $this->sortValue($a) : $this->sortValue($a) <=> $this->sortValue($b);

        return $order !== 0 ? $order : [$b->current->impressions, $a->key] <=> [$a->current->impressions, $b->key];
    }

    /**
     * Words separated by spaces (Search Console stores queries with single spaces).
     */
    public static function words(string $key): int
    {
        $key = trim($key);

        return $key === '' ? 0 : substr_count($key, ' ') + 1;
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private static function terms(array $terms): array
    {
        $clean = [];
        foreach ($terms as $term) {
            $term = mb_strtolower(trim($term));
            if ($term !== '' && !in_array($term, $clean, true)) {
                if (mb_strlen($term) > self::MAX_TERM_LENGTH) {
                    throw new \InvalidArgumentException('Search terms have at most ' . self::MAX_TERM_LENGTH . ' characters.');
                }
                $clean[] = $term;
            }
        }
        if (count($clean) > self::MAX_TERMS) {
            throw new \InvalidArgumentException('Use at most ' . self::MAX_TERMS . ' search terms.');
        }

        return $clean;
    }
}

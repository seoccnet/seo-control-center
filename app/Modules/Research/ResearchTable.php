<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use SeoControlCenter\Domain\Research\ResearchDomain;
use SeoControlCenter\Domain\Research\ResearchKeyword;

/**
 * Sorting and filtering of a stored research table from the query string.
 * Each table uses its own parameter prefix (for example r_sort, r_dir,
 * r_q, r_vmin), so the tables on one page do not affect each other.
 */
final class ResearchTable
{
    public const int MAX_ROWS = 200;

    public const array KEYWORD_COLUMNS = ['keyword', 'volume', 'difficulty', 'intent', 'position', 'traffic', 'cpc'];
    public const array DOMAIN_COLUMNS = ['domain', 'shared', 'average', 'keywords', 'top3', 'top10', 'traffic'];
    private const array KEYWORD_FILTERS = ['q', 'vmin', 'vmax', 'dmax', 'pmin', 'pmax', 'm', 'tool', 'rel'];
    /** Value of the modifier filter that matches a keyword containing any of the lookup's modifiers. */
    public const string ANY_MODIFIER = '*';

    /**
     * @param list<string> $columns
     * @param array<string, string> $filters
     */
    private function __construct(
        public readonly string $prefix,
        public readonly string $sort,
        public readonly string $dir,
        public readonly array $filters,
        public readonly array $columns,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @param list<string> $columns
     */
    public static function fromQuery(array $query, string $prefix, array $columns, string $defaultSort, string $defaultDir): self
    {
        $sort = is_string($query[$prefix . '_sort'] ?? null) && in_array($query[$prefix . '_sort'], $columns, true) ? $query[$prefix . '_sort'] : $defaultSort;
        $dir = ($query[$prefix . '_dir'] ?? null) === 'asc' || ($query[$prefix . '_dir'] ?? null) === 'desc' ? (string) $query[$prefix . '_dir'] : (isset($query[$prefix . '_sort']) ? self::naturalDir($sort) : $defaultDir);
        $filters = [];
        foreach (self::KEYWORD_FILTERS as $name) {
            $value = $query[$prefix . '_' . $name] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            if ($name === 'q') {
                $filters[$name] = mb_substr($value, 0, 100);
            } elseif ($name === 'm') {
                $filters[$name] = mb_substr(mb_strtolower($value), 0, 40);
            } elseif ($name === 'tool') {
                if ($value === '1') {
                    $filters[$name] = '1';
                }
            } elseif ($name === 'rel') {
                if ($value === 'all' || $value === 'not') {
                    $filters[$name] = $value;
                }
            } elseif (preg_match('/^\d{1,9}$/', $value) === 1) {
                $filters[$name] = $value;
            }
        }

        return new self($prefix, $sort, $dir, $filters, $columns);
    }

    /**
     * Keywords matching the filters, sorted.
     *
     * @param list<ResearchKeyword> $keywords
     * @param list<string> $modifiers the lookup's modifiers, for the "any modifier" filter
     * @param \SeoControlCenter\Domain\Opportunities\Relevance|null $relevance hides not-relevant keywords unless rel=all (rel=not shows only them)
     * @return list<ResearchKeyword>
     */
    public function keywords(array $keywords, array $modifiers = [], ?\SeoControlCenter\Domain\Opportunities\Relevance $relevance = null): array
    {
        $f = $this->filters;
        $keywords = array_values(array_filter($keywords, static function (ResearchKeyword $k) use ($f, $modifiers, $relevance): bool {
            $notRelevant = $relevance !== null && $relevance->of($k->keyword)['verdict'] === \SeoControlCenter\Domain\Opportunities\Relevance::NOT_RELEVANT;

            return ($relevance === null || match ($f['rel'] ?? '') {
                'all' => true, 'not' => $notRelevant, default => !$notRelevant
            })
                && (!isset($f['q']) || str_contains(mb_strtolower($k->keyword), mb_strtolower($f['q'])))
                && (!isset($f['m']) || ($f['m'] === self::ANY_MODIFIER
                    ? array_any($modifiers, static fn(string $m): bool => \SeoControlCenter\Domain\Opportunities\QueryPatterns::contains($k->keyword, $m))
                    : \SeoControlCenter\Domain\Opportunities\QueryPatterns::contains($k->keyword, $f['m'])))
                && (!isset($f['tool']) || \SeoControlCenter\Domain\Opportunities\QueryPatterns::toolNoun($k->keyword) !== null)
                && (!isset($f['vmin']) || ($k->searchVolume ?? 0) >= (int) $f['vmin'])
                && (!isset($f['vmax']) || ($k->searchVolume ?? 0) <= (int) $f['vmax'])
                && (!isset($f['dmax']) || ($k->difficulty ?? 0) <= (int) $f['dmax'])
                && (!isset($f['pmin']) || ($k->position !== null && $k->position >= (int) $f['pmin']))
                && (!isset($f['pmax']) || ($k->position !== null && $k->position <= (int) $f['pmax']));
        }));
        $value = fn(ResearchKeyword $k): string|int|float|null => match ($this->sort) {
            'keyword' => mb_strtolower($k->keyword),
            'volume' => $k->searchVolume,
            'difficulty' => $k->difficulty,
            'intent' => $k->intent,
            'position' => $k->position,
            'traffic' => $k->traffic,
            'cpc' => $k->cpc,
            default => null,
        };
        usort($keywords, fn(ResearchKeyword $a, ResearchKeyword $b): int => $this->compare($value($a), $value($b)) ?: strcmp($a->keyword, $b->keyword));

        return $keywords;
    }

    /**
     * @param list<ResearchDomain> $domains
     * @return list<ResearchDomain>
     */
    public function domains(array $domains): array
    {
        $value = fn(ResearchDomain $d): string|int|float|null => match ($this->sort) {
            'domain' => $d->domain,
            'shared' => $d->sharedKeywords,
            'average' => $d->averagePosition,
            'keywords' => $d->keywords,
            'top3' => $d->top3,
            'top10' => $d->top10,
            'traffic' => $d->traffic,
            default => null,
        };
        usort($domains, fn(ResearchDomain $a, ResearchDomain $b): int => $this->compare($value($a), $value($b)) ?: strcmp($a->domain, $b->domain));

        return $domains;
    }

    public function hasFilters(): bool
    {
        return $this->filters !== [];
    }

    public function filter(string $name): string
    {
        return $this->filters[$name] ?? '';
    }

    /**
     * Query parameters of this table, for links and hidden form fields.
     *
     * @return array<string, string>
     */
    public function params(bool $withFilters = true): array
    {
        $params = [$this->prefix . '_sort' => $this->sort, $this->prefix . '_dir' => $this->dir];
        if ($withFilters) {
            foreach ($this->filters as $name => $value) {
                $params[$this->prefix . '_' . $name] = $value;
            }
        }

        return $params;
    }

    /**
     * Parameters that sort by $column (reversing the order when it is already sorted by it).
     *
     * @return array<string, string>
     */
    public function sortParams(string $column): array
    {
        $dir = $column === $this->sort ? ($this->dir === 'asc' ? 'desc' : 'asc') : self::naturalDir($column);

        return [...$this->params(), $this->prefix . '_sort' => $column, $this->prefix . '_dir' => $dir];
    }

    /**
     * ▲/▼ after the header of the sorted column.
     */
    public function arrow(string $column): string
    {
        return $column === $this->sort ? ($this->dir === 'asc' ? ' ▲' : ' ▼') : '';
    }

    /**
     * Ranks, positions and difficulty read best from low to high; amounts from high to low.
     */
    private static function naturalDir(string $column): string
    {
        return in_array($column, ['keyword', 'domain', 'intent', 'position', 'difficulty', 'average'], true) ? 'asc' : 'desc';
    }

    /**
     * Empty values always last.
     */
    private function compare(string|int|float|null $a, string|int|float|null $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null) <=> ($b === null);
        }

        return $this->dir === 'asc' ? $a <=> $b : $b <=> $a;
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Rankings\Visibility;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;

/** Impression-weighted empirical CTR with 1,000 prior impressions per position. */
final readonly class CtrModel
{
    private const int PRIOR = 1000;

    /** @param array<int, array{clicks: int, impressions: int}> $bins */
    public function __construct(private array $bins = []) {}

    /** @param list<DimensionRow> $queries
     * @param list<string> $brandTerms
     */
    public static function train(array $queries, ?string $domain, array $brandTerms = []): self
    {
        $labels = explode('.', strtolower($domain ?? ''));
        array_pop($labels); // A public suffix is not a brand term.
        foreach ($labels as $domainTerm) {
            if (mb_strlen($domainTerm) >= 3 && !in_array($domainTerm, ['www', 'shop', 'store', 'blog', 'com', 'net', 'org', 'test'], true)) {
                $brandTerms[] = $domainTerm;
            }
        }
        $bins = [];
        foreach ($queries as $row) {
            $m = $row->metrics;
            $position = (int) round($m->position);
            if ($m->impressions <= 0 || $m->clicks < 0 || $m->clicks > $m->impressions || $position < 1 || $position > 20) {
                continue;
            }
            $query = Keyword::normalize($row->key);
            if ($query === '') {
                continue;
            }
            $brand = $position <= 3 && $m->impressions >= 100 && $m->ctr() >= .45;
            foreach ($brandTerms as $term) {
                $term = Keyword::normalize($term);
                if ($term !== '' && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/u', $query) === 1) {
                    $brand = true;
                }
            }
            if ($brand) {
                continue;
            }
            $bins[$position] ??= ['clicks' => 0, 'impressions' => 0];
            $bins[$position]['clicks'] += $m->clicks;
            $bins[$position]['impressions'] += $m->impressions;
        }
        return new self($bins);
    }

    public function hasSamples(): bool
    {
        return $this->bins !== [];
    }

    public function expected(?int $position): float
    {
        $bin = $this->bins[$position ?? 0] ?? ['clicks' => 0, 'impressions' => 0];
        return ($bin['clicks'] + self::PRIOR * Visibility::ctr($position)) / ($bin['impressions'] + self::PRIOR);
    }

    public function basis(?int $position): string
    {
        $n = $this->bins[$position ?? 0]['impressions'] ?? 0;
        return sprintf('Expected CTR %.2f%%; %s, %s non-brand impressions at position %s; blended with %d prior impressions. Aggregate devices; bounded Google query sample.', $this->expected($position) * 100, $n > 0 ? "your site's curve" : 'global fallback', number_format($n), $position ?? '?', self::PRIOR);
    }

    /** Two-sided 95% Wilson upper bound: require even the plausible upper CTR below half expected. */
    public static function upper(int $clicks, int $impressions): float
    {
        if ($impressions <= 0) {
            return 1.0;
        }
        $p = max(0.0, min(1.0, $clicks / $impressions));
        $z2 = 1.96 ** 2;
        return ($p + $z2 / (2 * $impressions) + 1.96 * sqrt($p * (1 - $p) / $impressions + $z2 / (4 * $impressions ** 2))) / (1 + $z2 / $impressions);
    }
}

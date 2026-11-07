<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Clusters;

/**
 * Keeps a cluster's public ID across re-analysis: a new cluster takes the
 * ID of the previous cluster whose keywords it shares most, when the
 * Jaccard overlap is at least 0.5. Each previous ID is used once, best
 * matches first, so a manual owner follows its topic.
 */
final class ClusterMatcher
{
    public const float MIN_JACCARD = 0.5;

    /**
     * @param array<string, list<string>> $previous public ID => keywords
     * @param list<list<string>> $current keywords of each new cluster
     * @return array<int, string> index in $current => matched public ID
     */
    public static function match(array $previous, array $current): array
    {
        $pairs = [];
        foreach ($current as $i => $members) {
            $set = array_fill_keys($members, true);
            foreach ($previous as $id => $old) {
                $shared = count(array_intersect_key($set, array_fill_keys($old, true)));
                if ($shared === 0) {
                    continue;
                }
                $jaccard = $shared / (count($set) + count(array_unique($old)) - $shared);
                if ($jaccard >= self::MIN_JACCARD) {
                    $pairs[] = [$jaccard, $i, (string) $id];
                }
            }
        }
        usort($pairs, static fn(array $a, array $b): int => [$b[0], $a[1], $a[2]] <=> [$a[0], $b[1], $b[2]]);
        $result = [];
        $used = [];
        foreach ($pairs as [, $i, $id]) {
            if (!isset($result[$i]) && !isset($used[$id])) {
                $result[$i] = $id;
                $used[$id] = true;
            }
        }
        ksort($result);

        return $result;
    }
}

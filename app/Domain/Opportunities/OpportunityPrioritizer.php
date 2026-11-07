<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * Orders findings from local evidence only. Action plans (PlaybookLibrary,
 * ADR-031) are guidance and never enter ordering.
 */
final class OpportunityPrioritizer
{
    public static function blocked(StoredOpportunity $row): bool
    {
        return array_any($row->evidence, static fn(array $item): bool => $item['label'] === 'Prerequisites');
    }

    public static function compare(StoredOpportunity $a, StoredOpportunity $b): int
    {
        return [self::blocked($a), -$a->priority, $a->publicId] <=> [self::blocked($b), -$b->priority, $b->publicId];
    }

    /** @param list<StoredOpportunity> $rows
     * @return list<StoredOpportunity> no type can consume more than three of ten places
     */
    public function weekly(array $rows): array
    {
        usort($rows, self::compare(...));
        $counts = [];
        $result = [];
        foreach ($rows as $row) {
            if (($counts[$row->type->value] ?? 0) >= 3) {
                continue;
            }
            $counts[$row->type->value] = ($counts[$row->type->value] ?? 0) + 1;
            $result[] = $row;
            if (count($result) === 10) {
                break;
            }
        }
        return $result;
    }

    public static function explanation(StoredOpportunity $row, ?StoredOpportunity $next): string
    {
        $formula = sprintf('%d impact × %d confidence / 100 / %d effort = %.2f priority.', $row->impact, $row->confidence, $row->effort, $row->priority);
        if ($next === null) {
            return $formula . ' Unblocked work precedes work with prerequisites; equal priority uses the stable UUID. Weekly selection allows up to three findings per primary type.';
        }
        return $formula . (self::blocked($row) !== self::blocked($next) ? ' Unblocked work precedes work with prerequisites.' : sprintf(' The next action has %.2f priority; equal scores use the stable UUID.', $next->priority));
    }
}

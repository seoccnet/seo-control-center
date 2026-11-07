<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/** Presentation consolidation preserves every stored UUID, subject hash and workflow decision. */
final class OpportunityConsolidator
{
    public static function key(StoredOpportunity $row): string
    {
        foreach ($row->evidence as $item) {
            if (in_array($item['label'], ['Query', 'Keyword'], true) && $item['value'] !== '') {
                return 'query:' . Keyword::normalize($item['value']);
            }
        }
        $page = self::page($row);
        return $page !== null ? 'page:' . $page : 'finding:' . $row->publicId;
    }

    public static function page(StoredOpportunity $row): ?string
    {
        $url = self::pageUrl($row);

        return $url === null ? null : self::pageKey($url);
    }

    /** The page address as the evidence states it, or null. */
    public static function pageUrl(StoredOpportunity $row): ?string
    {
        foreach ($row->evidence as $item) {
            if (in_array($item['label'], ['Page', 'Ranking URL', 'URL', 'Value page'], true) && $item['value'] !== '—') {
                $url = $item['value'];
                if ($item['label'] === 'Value page') {
                    $url = explode(' (', $url, 2)[0];
                }
                if (is_string(parse_url($url, PHP_URL_PATH))) {
                    return $url;
                }
            }
        }
        return null;
    }

    /** Lower-case host plus path without a trailing slash: the same page whatever the scheme or slash. */
    public static function pageKey(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $host = parse_url($url, PHP_URL_HOST);

        return (is_string($host) ? mb_strtolower($host) : '') . (rtrim(is_string($path) ? $path : '', '/') ?: '/');
    }

    /** @param list<StoredOpportunity> $rows
     * @return list<StoredOpportunity>
     */
    public function consolidate(array $rows, string $status, ?OpportunityType $type = null): array
    {
        $groups = [];
        $closed = [];
        foreach ($rows as $row) {
            $key = self::key($row);
            if (in_array($row->status, [StoredOpportunity::DONE, StoredOpportunity::DISMISSED, StoredOpportunity::SNOOZED], true)) {
                $closed[$key] = true;
            }
            if ($row->status === $status) {
                $groups[$key][] = $row;
            }
        }
        $result = [];
        foreach ($groups as $key => $members) {
            if ($status === StoredOpportunity::OPEN && isset($closed[$key])) {
                continue;
            }
            usort($members, OpportunityPrioritizer::compare(...));
            $primary = $members[0];
            if ($type !== null && !array_any($members, static fn(StoredOpportunity $row): bool => $row->type === $type)) {
                continue;
            }
            $evidence = $primary->evidence;
            $evidence[] = ['label' => 'Canonical subject', 'value' => $key];
            $evidence[] = ['label' => 'Consolidation', 'value' => 'Unblocked work precedes dependent work; otherwise highest priority is primary; other signals support the same subject. Estimates are not added across overlapping rules. Stored identities and status histories remain unchanged.'];
            foreach (array_slice($members, 1, 50) as $other) {
                $evidence[] = ['label' => 'Supporting signal', 'value' => $other->type->label() . ': ' . $other->title . ' (priority ' . $other->priority . '; opportunity ' . $other->publicId . ')'];
                $evidence[] = ['label' => 'Supporting action', 'value' => $other->explanation];
                foreach (array_slice($other->evidence, 0, 8) as $item) {
                    $evidence[] = ['label' => $other->type->label() . ' supporting: ' . $item['label'], 'value' => $item['value']];
                }
            }
            $result[] = new StoredOpportunity($primary->publicId, $primary->type, $primary->title, $primary->explanation, $evidence, $primary->impact, $primary->confidence, $primary->effort, $primary->priority, $primary->status, $primary->firstDetectedAt, $primary->lastDetectedAt, $primary->estimatedClicks, $primary->rawEstimatedClicks, $primary->snoozedUntil, $primary->subject);
        }
        usort($result, OpportunityPrioritizer::compare(...));
        return $result;
    }

    /** @param list<StoredOpportunity> $rows
     * @return list<array{page: string, estimated_monthly_clicks: ?float, unknown_estimates: int, actions: list<StoredOpportunity>, notice: string}>
     */
    public function pages(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $page = self::page($row);
            if ($page !== null) {
                $groups[$page][] = $row;
            }
        }
        $result = [];
        foreach ($groups as $page => $actions) {
            $queries = $pageGain = 0.0;
            $known = $unknown = 0;
            foreach ($actions as $row) {
                if ($row->estimatedClicks === null) {
                    ++$unknown;
                } else {
                    ++$known;
                    if (str_starts_with(self::key($row), 'query:')) {
                        $queries += max(0.0, $row->estimatedClicks);
                    } else {
                        $pageGain = max($pageGain, $row->estimatedClicks);
                    }
                }
            }
            $result[] = ['page' => $page, 'estimated_monthly_clicks' => $known > 0 ? max($queries, $pageGain) : null, 'unknown_estimates' => $unknown, 'actions' => $actions, 'notice' => 'Sum of primary query estimates or the page estimate, whichever is greater; overlapping page/query estimates are not added. Unknown gains excluded. Scenario estimate, not a forecast or causal claim.'];
        }
        return $result;
    }
}

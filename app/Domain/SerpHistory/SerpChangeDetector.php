<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SerpHistory;

use SeoControlCenter\Domain\Comparison\ResultsFormat;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Opportunities\SerpFormat;
use SeoControlCenter\Domain\Rankings\RankCheck;

/**
 * What changed between two comparable checks of one tracked keyword
 * (ADR-039, E1): result features, tracked competitors in the top 10, newly
 * ranking URLs of competitors and the project, the project's own movement
 * and the dominant page type. Pure; reads only the two stored checks.
 */
final class SerpChangeDetector
{
    public const string FEATURE_ADDED = 'feature_added';
    public const string FEATURE_REMOVED = 'feature_removed';
    public const string COMPETITOR_ENTERED = 'competitor_entered';
    public const string COMPETITOR_LEFT = 'competitor_left';
    public const string NEW_URL = 'new_url';
    public const string OWN_ENTERED = 'own_entered';
    public const string OWN_LEFT = 'own_left';
    public const string OWN_MOVED = 'own_moved';
    public const string FORMAT_SHIFT = 'format_shift';
    public const array KINDS = [self::FEATURE_ADDED, self::FEATURE_REMOVED, self::COMPETITOR_ENTERED, self::COMPETITOR_LEFT, self::NEW_URL, self::OWN_ENTERED, self::OWN_LEFT, self::OWN_MOVED, self::FORMAT_SHIFT];
    public const int TOP = 10;
    public const int MOVE = 5;
    public const int MAX_PER_CHECK = 20;

    /**
     * @param list<string> $competitors tracked competitor domains
     * @return list<array{kind: string, subject: string, detail: array<string, mixed>}>
     */
    public static function compare(RankCheck $previous, RankCheck $current, string $ownDomain, array $competitors): array
    {
        $events = [];
        $before = array_fill_keys(array_map('strtolower', $previous->features), true);
        $after = array_fill_keys(array_map('strtolower', $current->features), true);
        foreach (array_keys(array_diff_key($after, $before)) as $feature) {
            $events[] = ['kind' => self::FEATURE_ADDED, 'subject' => (string) $feature, 'detail' => ['own_position' => $current->position]];
        }
        foreach (array_keys(array_diff_key($before, $after)) as $feature) {
            $events[] = ['kind' => self::FEATURE_REMOVED, 'subject' => (string) $feature, 'detail' => ['own_position' => $current->position]];
        }

        $was = $previous->position !== null && $previous->position <= self::TOP;
        $is = $current->position !== null && $current->position <= self::TOP;
        $own = ['from' => $previous->position, 'to' => $current->position, 'url' => $current->url ?? $previous->url];
        if (!$was && $is) {
            $events[] = ['kind' => self::OWN_ENTERED, 'subject' => 'top10', 'detail' => $own];
        } elseif ($was && !$is) {
            $events[] = ['kind' => self::OWN_LEFT, 'subject' => 'top10', 'detail' => $own];
        } elseif ($previous->position !== null && $current->position !== null && abs($previous->position - $current->position) >= self::MOVE) {
            $events[] = ['kind' => self::OWN_MOVED, 'subject' => 'position', 'detail' => $own];
        }

        $top = static fn(RankCheck $c): array => array_values(array_filter($c->top, static fn(array $r): bool => $r['position'] <= self::TOP));
        $prevTop = $top($previous);
        $curTop = $top($current);
        $ownDomain = strtolower((string) preg_replace('/^www\./i', '', $ownDomain));
        $watched = array_values(array_unique(array_filter([$ownDomain, ...array_map('strtolower', $competitors)])));
        $best = static function (array $rows, string $domain): ?array {
            foreach ($rows as $r) {
                if (Competitor::owns($domain, $r['domain'])) {
                    return $r;
                }
            }

            return null;
        };
        foreach ($competitors as $domain) {
            $domain = strtolower($domain);
            $p = $best($prevTop, $domain);
            $c = $best($curTop, $domain);
            if ($p === null && $c !== null) {
                $events[] = ['kind' => self::COMPETITOR_ENTERED, 'subject' => $domain, 'detail' => ['position' => $c['position'], 'url' => $c['url'], 'above_you' => $current->position === null || $c['position'] < $current->position]];
            } elseif ($p !== null && $c === null) {
                $events[] = ['kind' => self::COMPETITOR_LEFT, 'subject' => $domain, 'detail' => ['position' => $p['position'], 'url' => $p['url']]];
            }
        }
        $prevUrls = array_fill_keys(array_map(static fn(array $r): string => self::url($r['url']), $prevTop), true);
        foreach ($curTop as $r) {
            if (isset($prevUrls[self::url($r['url'])])) {
                continue;
            }
            foreach ($watched as $domain) {
                if (Competitor::owns($domain, $r['domain'])) {
                    $events[] = ['kind' => self::NEW_URL, 'subject' => mb_substr($r['url'], 0, 2048), 'detail' => ['domain' => $domain, 'position' => $r['position'], 'own' => $domain === $ownDomain]];
                    break;
                }
            }
        }

        if ($prevTop !== [] && $curTop !== []) {
            $from = self::dominant($prevTop);
            $to = self::dominant($curTop);
            if ($from !== $to) {
                $events[] = ['kind' => self::FORMAT_SHIFT, 'subject' => $from . '→' . $to, 'detail' => ['from' => $from, 'to' => $to]];
            }
        }

        return array_slice($events, 0, self::MAX_PER_CHECK);
    }

    /**
     * The dominant page type of a top 10 from the result addresses, or
     * "mixed"/"fragmented" when no single type holds the page.
     *
     * @param list<array{position: int, domain: string, url: string}> $top
     */
    public static function dominant(array $top): string
    {
        $format = ResultsFormat::decide(array_map(static fn(array $r): array => ['position' => $r['position'], 'type' => SerpFormat::classify($r['url'])], $top));

        return $format['verdict'] === ResultsFormat::DOMINANT ? (string) ($format['types'][0] ?? SerpFormat::OTHER) : $format['verdict'];
    }

    private static function url(string $url): string
    {
        return rtrim((string) preg_replace('#^https?://(www\.)?#i', '', strtolower($url)), '/');
    }
}

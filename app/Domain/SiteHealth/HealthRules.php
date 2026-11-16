<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SiteHealth;

/**
 * When a site health check becomes an event (1.3.0). Pure: every rule
 * takes the previous and the new observation and returns the events to
 * record, each with a dedupe key so it is recorded once.
 *
 * @phpstan-type Event array{kind: string, key: string, title: string, detail: string}
 */
final class HealthRules
{
    /** Consecutive failed checks before the site counts as down (one failure may be a hiccup). */
    public const int DOWN_AFTER = 2;
    /** Days before a certificate expires when a warning is sent; each threshold once. */
    public const array SSL_WARN_DAYS = [14, 3];
    public const array DOMAIN_WARN_DAYS = [30, 7];
    /** A sitemap shrinking by this share (and by at least MIN_SITEMAP_DROP URLs) is reported. */
    public const float SITEMAP_DROP = 0.1;
    public const int MIN_SITEMAP_DROP = 10;

    public const string DOWN = 'down';
    public const string RECOVERED = 'recovered';
    public const string SSL = 'ssl';
    public const string DOMAIN = 'domain';
    public const string ROBOTS = 'robots';
    public const string SITEMAP = 'sitemap';

    /** Whether an answer means the site is up: any response below 500 except none at all. */
    public static function isUp(?int $status): bool
    {
        return $status !== null && $status > 0 && $status < 500;
    }

    /**
     * @return list<Event>
     */
    public static function uptime(int $failuresBefore, ?string $downSince, bool $up, ?int $status, ?string $error, string $now): array
    {
        if ($up) {
            return $downSince !== null ? [[
                'kind' => self::RECOVERED,
                'key' => 'up:' . $downSince,
                'title' => 'The site answers again',
                'detail' => sprintf('Down since %s UTC, answering again at %s UTC (HTTP %d).', $downSince, $now, (int) $status),
            ]] : [];
        }
        if ($failuresBefore + 1 === self::DOWN_AFTER) {
            return [[
                'kind' => self::DOWN,
                'key' => 'down:' . $now,
                'title' => 'The site does not answer',
                'detail' => sprintf('%d checks in a row failed: %s.', self::DOWN_AFTER, $status !== null && $status > 0 ? 'HTTP ' . $status : ($error ?? 'no answer')),
            ]];
        }

        return [];
    }

    /**
     * @return list<Event>
     */
    public static function expiry(string $kind, ?string $expiresAt, ?string $error, string $now, string $what): array
    {
        if ($expiresAt === null) {
            return [];
        }
        $days = (int) floor((strtotime($expiresAt) - strtotime($now)) / 86400);
        $thresholds = $kind === self::SSL ? self::SSL_WARN_DAYS : self::DOMAIN_WARN_DAYS;
        $hit = null;
        foreach ($thresholds as $threshold) {
            if ($days <= $threshold) {
                $hit = $threshold;
            }
        }
        if ($hit === null) {
            return [];
        }

        return [[
            'kind' => $kind,
            'key' => $kind . ':' . substr($expiresAt, 0, 10) . ':' . $hit,
            'title' => $days < 0 ? ucfirst($what) . ' has expired' : sprintf('%s expires in %d day%s', ucfirst($what), $days, $days === 1 ? '' : 's'),
            'detail' => sprintf('%s expires on %s UTC.%s', ucfirst($what), substr($expiresAt, 0, 16), $kind === self::SSL ? ' Renew it at your host; most renew automatically, so check that the renewal works.' : ' Renew it at your registrar, or turn on automatic renewal.'),
        ]];
    }

    /**
     * @return list<Event>
     */
    public static function robots(?int $statusBefore, ?string $hashBefore, ?string $textBefore, int $status, string $hash, string $text): array
    {
        if ($hashBefore === null || ($statusBefore === $status && $hashBefore === $hash)) {
            return [];
        }
        $detail = $statusBefore !== $status
            ? sprintf('robots.txt now answers HTTP %d (was %d).', $status, (int) $statusBefore)
            : 'robots.txt changed.';
        $diff = self::lineDiff((string) $textBefore, $text);
        foreach (array_slice($diff['added'], 0, 5) as $line) {
            $detail .= "\n+ " . mb_substr($line, 0, 200);
        }
        foreach (array_slice($diff['removed'], 0, 5) as $line) {
            $detail .= "\n− " . mb_substr($line, 0, 200);
        }
        $blocksAll = preg_match('/^\s*disallow\s*:\s*\/\s*$/mi', $text) === 1 && preg_match('/^\s*disallow\s*:\s*\/\s*$/mi', (string) $textBefore) !== 1;

        return [[
            'kind' => self::ROBOTS,
            'key' => 'robots:' . $status . ':' . $hash,
            'title' => $blocksAll ? 'robots.txt now blocks the whole site' : 'robots.txt changed',
            'detail' => mb_substr($detail, 0, 2000),
        ]];
    }

    /**
     * @return list<Event>
     */
    public static function sitemap(?int $before, int $after, string $today): array
    {
        if ($before === null || $before < self::MIN_SITEMAP_DROP || $before - $after < max(self::MIN_SITEMAP_DROP, (int) ceil($before * self::SITEMAP_DROP))) {
            return [];
        }

        return [[
            'kind' => self::SITEMAP,
            'key' => 'sitemap:' . $today . ':' . $after,
            'title' => $after === 0 ? 'The sitemap lists no pages any more' : sprintf('The sitemap lost %d pages', $before - $after),
            'detail' => $after === 0
                ? sprintf('The sitemap listed %d pages; now none could be read. Check that it still exists and loads.', $before)
                : sprintf('The sitemap lists %d pages, down from %d. Check whether pages were removed on purpose.', $after, $before),
        ]];
    }

    /**
     * Lines added and removed, ignoring blank lines and order.
     *
     * @return array{added: list<string>, removed: list<string>}
     */
    public static function lineDiff(string $before, string $after): array
    {
        $lines = static fn(string $text): array => array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), static fn(string $l): bool => $l !== '')));
        $old = $lines($before);
        $new = $lines($after);

        return ['added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new))];
    }
}

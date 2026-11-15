<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SiteMigration;

/**
 * Grades one tested redirect of a site migration (1.2.0) from what the old
 * address answered and, when it redirected, what the address it pointed at
 * answered. Addresses are compared with the scheme and host in lower case
 * and an empty path read as "/"; /shoes and /shoes/ are different addresses.
 */
final class RedirectCheck
{
    public const string OK = 'ok';
    public const string TEMPORARY = 'temporary';
    public const string WRONG_TARGET = 'wrong_target';
    public const string CHAIN = 'chain';
    public const string TARGET_BROKEN = 'target_broken';
    public const string NOT_REDIRECTED = 'not_redirected';
    public const string MISSING = 'missing';
    public const string FAILED = 'failed';
    public const string SKIPPED = 'skipped';
    public const string UNTESTED = 'untested';

    /** States that need fixing before or after launch. */
    public const array PROBLEMS = [self::TEMPORARY, self::WRONG_TARGET, self::CHAIN, self::TARGET_BROKEN, self::NOT_REDIRECTED, self::MISSING];

    /**
     * @param array{status: ?int, location: ?string, error: ?string, skipped?: bool} $old what the old address answered
     * @param array{status: ?int, location: ?string, error: ?string, skipped?: bool}|null $target what the redirect target answered, when fetched
     * @param ?string $expected the chosen new address; null when the row has none
     * @return array{state: string, detail: string}
     */
    public static function grade(array $old, ?array $target, ?string $expected): array
    {
        if (($old['skipped'] ?? false) === true) {
            return ['state' => self::SKIPPED, 'detail' => $old['error'] ?? 'Not tested.'];
        }
        $status = $old['status'];
        if ($status === null || $status === 0) {
            return ['state' => self::FAILED, 'detail' => $old['error'] ?? 'No answer.'];
        }
        if ($status >= 200 && $status < 300) {
            return ['state' => self::NOT_REDIRECTED, 'detail' => sprintf('HTTP %d: the old address still answers itself', $status)];
        }
        if ($status === 404 || $status === 410) {
            return ['state' => self::MISSING, 'detail' => sprintf('HTTP %d: not redirected and gone', $status)];
        }
        if ($status < 300 || $status >= 400) {
            return ['state' => self::FAILED, 'detail' => sprintf('HTTP %d', $status)];
        }
        $location = $old['location'];
        if ($location === null) {
            return ['state' => self::FAILED, 'detail' => sprintf('HTTP %d without a Location header', $status)];
        }
        $temporary = in_array($status, [302, 303, 307], true);
        if ($expected !== null && self::same($location, $expected) === false) {
            // Pointing elsewhere, unless that address forwards to the expected one.
            if ($target !== null && $target['status'] !== null && $target['status'] >= 300 && $target['status'] < 400 && $target['location'] !== null && self::same($target['location'], $expected)) {
                return ['state' => self::CHAIN, 'detail' => sprintf('%d → %s → %s', $status, $location, $expected)];
            }

            return ['state' => self::WRONG_TARGET, 'detail' => sprintf('%d → %s (expected %s)', $status, $location, $expected)];
        }
        if ($target === null || ($target['skipped'] ?? false) === true) {
            return $temporary
                ? ['state' => self::TEMPORARY, 'detail' => sprintf('%d is temporary; use 301 or 308', $status)]
                : ['state' => self::OK, 'detail' => sprintf('%d → %s (target not checked)', $status, $location)];
        }
        $targetStatus = $target['status'];
        if ($targetStatus === null || $targetStatus === 0) {
            return ['state' => self::TARGET_BROKEN, 'detail' => sprintf('%d → %s, which did not answer', $status, $location)];
        }
        if ($targetStatus >= 300 && $targetStatus < 400) {
            return ['state' => self::CHAIN, 'detail' => sprintf('%d → %s → %s', $status, $location, $target['location'] ?? '?')];
        }
        if ($targetStatus >= 400) {
            return ['state' => self::TARGET_BROKEN, 'detail' => sprintf('%d → %s, which returns HTTP %d', $status, $location, $targetStatus)];
        }
        if ($temporary) {
            return ['state' => self::TEMPORARY, 'detail' => sprintf('%d is temporary; use 301 or 308', $status)];
        }

        return ['state' => self::OK, 'detail' => sprintf('%d → %s', $status, $location)];
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::OK => 'Redirects correctly',
            self::TEMPORARY => 'Temporary redirect',
            self::WRONG_TARGET => 'Redirects elsewhere',
            self::CHAIN => 'Redirect chain',
            self::TARGET_BROKEN => 'Target broken',
            self::NOT_REDIRECTED => 'Not redirected yet',
            self::MISSING => 'Gone (404)',
            self::FAILED => 'Test failed',
            self::SKIPPED => 'Not tested',
            default => 'Untested',
        };
    }

    private static function same(string $a, string $b): bool
    {
        $normal = static function (string $url): string {
            $url = trim($url);
            if (preg_match('#^(https?://)([^/?\#]+)(.*)$#i', $url, $m) === 1) {
                $url = strtolower($m[1] . $m[2]) . ($m[3] === '' ? '/' : $m[3]);
            }

            return $url;
        };

        return $normal($a) === $normal($b);
    }
}

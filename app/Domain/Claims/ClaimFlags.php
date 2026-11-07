<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Claims;

/**
 * Automated checks of a claim's evidence (ADR-042). They point at what a
 * person should look at; they never change the person's verdict. A
 * competitor's statement is not independent validation, and one breeder's
 * specification does not carry over to another breeder's product.
 */
final class ClaimFlags
{
    public const int STALE_DAYS = 365;

    /**
     * @param list<string> $competitorDomains
     * @return list<array{code: string, level: string, message: string}>
     */
    public static function of(Claim $claim, string $projectDomain, array $competitorDomains, string $today): array
    {
        $flags = [];
        $host = $claim->sourceUrl !== null ? self::host((string) parse_url($claim->sourceUrl, PHP_URL_HOST)) : null;
        if ($claim->sourceUrl === null && $claim->status !== Claim::UNVERIFIABLE) {
            $flags[] = ['code' => 'no_source', 'level' => 'warning', 'message' => 'No source is recorded for this claim.'];
        }
        if ($host !== null && ($host === self::host($projectDomain) || str_ends_with($host, '.' . self::host($projectDomain)))) {
            $flags[] = ['code' => 'own_site', 'level' => 'warning', 'message' => 'The source is your own site, so it cannot confirm the claim independently.'];
        }
        foreach ($competitorDomains as $domain) {
            $domain = self::host($domain);
            if ($host !== null && $domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                $flags[] = ['code' => 'competitor', 'level' => 'warning', 'message' => 'The source is a competitor (' . $domain . '). A competitor\'s statement is not independent validation.'];
                break;
            }
        }
        foreach (['breeder', 'cultivar', 'version', 'market', 'sample'] as $field) {
            $want = $claim->claimScope[$field] ?? '';
            $have = $claim->sourceScope[$field] ?? '';
            if ($want !== '' && $have !== '' && !self::same($want, $have)) {
                $flags[] = ['code' => $field . '_mismatch', 'level' => 'error', 'message' => sprintf('The source is about %s "%s", the claim is about "%s". Its evidence does not carry over.', mb_strtolower(Claim::SCOPE[$field]), $have, $want)];
            }
        }
        $unscoped = array_keys(array_filter(['breeder' => $claim->claimScope['breeder'] ?? '', 'cultivar' => $claim->claimScope['cultivar'] ?? ''], static fn(string $v): bool => $v !== ''));
        $missing = array_values(array_filter($unscoped, static fn(string $f): bool => ($claim->sourceScope[$f] ?? '') === ''));
        if ($claim->sourceUrl !== null && $missing !== []) {
            $flags[] = ['code' => 'source_scope_unknown', 'level' => 'warning', 'message' => 'Record which ' . implode(' and ', array_map(static fn(string $f): string => mb_strtolower(Claim::SCOPE[$f]), $missing)) . ' the source describes, to check it covers the claim.'];
        }
        if (in_array($claim->status, [Claim::SUPPORTED, Claim::PARTLY], true) && trim($claim->passage) === '') {
            $flags[] = ['code' => 'no_passage', 'level' => 'warning', 'message' => 'Marked supported without the supporting passage or its location.'];
        }
        if ($claim->checkedOn !== null && (new \DateTimeImmutable($claim->checkedOn))->diff(new \DateTimeImmutable($today))->days > self::STALE_DAYS && $claim->checkedOn < $today) {
            $flags[] = ['code' => 'stale', 'level' => 'warning', 'message' => 'The source was checked more than a year ago; check it again.'];
        }

        return $flags;
    }

    /**
     * Two scope values name the same thing when, ignoring case and punctuation,
     * they have the same words, or one adds only numbers (a phenotype or
     * batch: "Brandywine" and "Brandywine #33"). Any other added word is another
     * product: "Brandywine" is not "Brandywine Cake Ice", nor "Brandywine Auto".
     */
    public static function same(string $a, string $b): bool
    {
        $words = static fn(string $s): array => array_values(array_filter(explode(' ', trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)))), static fn(string $w): bool => $w !== ''));
        $a = $words($a);
        $b = $words($b);
        if ($a === [] || $b === []) {
            return false;
        }
        $extra = array_merge(array_diff($a, $b), array_diff($b, $a));
        $shared = array_intersect($a, $b);

        return $shared !== [] && array_filter($extra, static fn(string $w): bool => !ctype_digit($w)) === [];
    }

    private static function host(string $host): string
    {
        return (string) preg_replace('/^www\./', '', strtolower(trim($host)));
    }
}

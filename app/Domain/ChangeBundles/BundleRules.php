<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ChangeBundles;

/**
 * Change bundles (ADR-042, 0.40.0): a reviewed set of exact edits to one
 * page. SEOcc never edits the live site. It records the bundle, binds the
 * approval to a hash of its content and live baseline, refuses to record a
 * deployment while the live page differs from that baseline, and verifies
 * the edits on the live page afterwards.
 *
 * @phpstan-type Edit array{field: string, before: string, after: string}
 * @phpstan-type Snapshot array<string, int|string|bool|null>
 * @phpstan-type Guard array{checked_at: string, matches: bool, changes: array<string, array{before: string, after: string}>}
 * @phpstan-type Check array{field: string, status: string, live: ?string}
 */
final class BundleRules
{
    public const array FIELDS = ['title' => 'Title', 'description' => 'Meta description', 'h1' => 'Main heading (H1)', 'canonical' => 'Canonical', 'noindex' => 'Noindex (yes or no)', 'content' => 'Body text', 'redirect' => 'Redirect', 'other' => 'Other'];
    /** Edits SEOcc can read back from the live page; the rest are checked by a person. */
    public const array CHECKABLE = ['title', 'description', 'h1', 'canonical', 'noindex'];
    public const array STATUSES = ['draft' => 'Draft', 'approved' => 'Approved', 'deployed' => 'Deployed', 'partially_deployed' => 'Partly deployed', 'verified' => 'Verified', 'rolled_back' => 'Rolled back'];
    public const array CHECK_STATUSES = ['matched' => 'Live', 'mismatch' => 'Not live', 'manual' => 'Check by hand', 'not_applied' => 'Not applied'];
    /** Page facts the guard compares; a change to any of them means the page is no longer the one approved. */
    public const array GUARDED = ['status' => 'HTTP status', 'title' => 'Title', 'description' => 'Meta description', 'h1s' => 'Main headings', 'canonical' => 'Canonical', 'noindex' => 'Noindex', 'words' => 'Word count', 'text' => 'Body text'];
    /** A guard check counts for this long, and only when made after the approval. */
    public const int GUARD_HOURS = 24;
    public const int MAX_EDITS = 10;
    /**
     * A bundle started from an opportunity (0.41.0): the edit fields its action plan usually
     * changes, and the primary metric of the measurement plan drafted on deployment.
     */
    public const array FROM_PLAYBOOK = [
        'low_ctr' => [['title', 'description'], 'ctr'],
        'click_erosion' => [['title', 'description'], 'ctr'],
        'quick_win' => [['title', 'h1', 'content'], 'clicks'],
        'ranking_decline' => [['content', 'title'], 'position'],
        'traffic_decline' => [['content', 'title'], 'clicks'],
        'content_refresh' => [['content', 'title'], 'clicks'],
        'format_mismatch' => [['content'], 'impressions'],
        'cannibalization' => [['canonical', 'redirect'], 'clicks'],
        'internal_links' => [['other'], 'impressions'],
        'ai_visibility:aio' => [['content'], 'impressions'],
        'site:missing_structured_data' => [['other'], 'clicks'],
        'site:missing_author' => [['content'], 'clicks'],
        'site:missing_sources' => [['content'], 'clicks'],
        'site:missing_content_markup' => [['other'], 'clicks'],
        'site:missing_questions' => [['content'], 'clicks'],
        'site:missing_spec_table' => [['content'], 'clicks'],
        'cannibalization:duplicate' => [['redirect', 'canonical'], 'clicks'],
        'internal_links:pillar' => [['content'], 'impressions'],
        'indexing' => [['canonical', 'noindex'], 'impressions'],
        'indexing:canonical' => [['canonical'], 'impressions'],
    ];

    /** @return array{0: list<string>, 1: string} suggested edit fields and the plan's primary metric */
    public static function fromPlaybook(string $key): array
    {
        return self::FROM_PLAYBOOK[$key] ?? [['other'], 'clicks'];
    }

    /**
     * The approval binds to this: what was reviewed and the live page it was reviewed against.
     *
     * @param list<Edit> $edits
     * @param Snapshot|null $baseline
     */
    public static function hash(string $title, string $problem, string $evidence, string $url, array $edits, string $expected, string $validation, ?array $baseline): string
    {
        return hash('sha256', (string) json_encode([$title, $problem, $evidence, $url, $edits, $expected, $validation, $baseline], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * What differs between the approved baseline and the live page now.
     *
     * @param Snapshot $baseline
     * @param Snapshot $live
     * @return array<string, array{before: string, after: string}>
     */
    public static function differences(array $baseline, array $live): array
    {
        $changes = [];
        foreach (array_keys(self::GUARDED) as $field) {
            $before = $baseline[$field] ?? null;
            $after = $live[$field] ?? null;
            if ($before !== $after) {
                $changes[$field] = $field === 'text'
                    ? ['before' => 'as approved', 'after' => 'changed']
                    : ['before' => self::display($before), 'after' => self::display($after)];
            }
        }

        return $changes;
    }

    /**
     * Why a deployment may not be recorded now, or null when it may.
     *
     * @param Guard|null $guard
     */
    public static function deploymentBlock(string $status, string $hash, ?string $approvedHash, ?string $approvedAt, ?array $guard, string $now): ?string
    {
        if ($status !== 'approved' || $approvedHash === null || $approvedAt === null) {
            return 'Approve the bundle first.';
        }
        if ($approvedHash !== $hash) {
            return 'The bundle changed after its approval. Approve it again.';
        }
        if ($guard === null || $guard['checked_at'] < $approvedAt) {
            return 'Check the live page first: the version guard compares it with the approved baseline.';
        }
        if ($guard['checked_at'] < (new \DateTimeImmutable($now))->modify('-' . self::GUARD_HOURS . ' hours')->format('Y-m-d H:i:s')) {
            return sprintf('The last live check is older than %d hours. Check the live page again.', self::GUARD_HOURS);
        }
        if (!$guard['matches']) {
            return 'The live page changed since the approval (' . implode(', ', array_map(static fn(string $f): string => self::GUARDED[$f] ?? $f, array_keys($guard['changes']))) . '). Capture a new baseline, review the edits against it and approve again.';
        }

        return null;
    }

    /**
     * Reads each edit back from the live page.
     *
     * @param list<Edit> $edits
     * @param list<bool> $applied
     * @param Snapshot $live
     * @param Snapshot|null $baseline
     * @return list<Check>
     */
    public static function verify(array $edits, array $applied, array $live, ?array $baseline): array
    {
        $checks = [];
        foreach ($edits as $i => $edit) {
            $field = $edit['field'];
            $after = trim($edit['after']);
            if (!($applied[$i] ?? false)) {
                $checks[] = ['field' => $field, 'status' => 'not_applied', 'live' => null];
                continue;
            }
            [$status, $value] = match ($field) {
                'title', 'description' => [self::same($live[$field] ?? null, $after) ? 'matched' : 'mismatch', self::text($live[$field] ?? null)],
                'h1' => [in_array(self::squash($after), array_map(self::squash(...), explode("\n", (string) ($live['h1s'] ?? ''))), true) ? 'matched' : 'mismatch', self::text($live['h1s'] ?? null)],
                'canonical' => [self::sameUrl($live['canonical'] ?? null, $after, (string) ($live['url'] ?? '')) ? 'matched' : 'mismatch', self::text($live['canonical'] ?? null)],
                'noindex' => [is_bool($live['noindex'] ?? null) && $live['noindex'] === in_array(strtolower($after), ['yes', 'true', '1', 'noindex'], true) ? 'matched' : 'mismatch', is_bool($live['noindex'] ?? null) ? ($live['noindex'] ? 'yes' : 'no') : null],
                'content' => ['manual', $baseline !== null && ($baseline['text'] ?? null) !== ($live['text'] ?? null) ? 'the body text changed since the baseline' : 'the body text is unchanged since the baseline'],
                default => ['manual', null],
            };
            $checks[] = ['field' => $field, 'status' => $status, 'live' => $value];
        }

        return $checks;
    }

    /**
     * The SEO change type a deployed bundle is recorded as on the change timeline.
     *
     * @param list<Edit> $edits
     */
    public static function changeKind(array $edits): string
    {
        $fields = array_column($edits, 'field');
        return match (true) {
            array_diff($fields, ['title', 'description']) === [] => 'title',
            array_diff($fields, ['title', 'description', 'h1', 'content']) === [] => 'content',
            array_diff($fields, ['canonical', 'noindex', 'redirect']) === [] => 'technical',
            default => 'other',
        };
    }

    public static function display(int|string|bool|null $value): string
    {
        return is_bool($value) ? ($value ? 'yes' : 'no') : ($value === null ? 'not present' : (string) $value);
    }

    private static function same(int|string|bool|null $live, string $after): bool
    {
        return is_string($live) && self::squash($live) === self::squash($after);
    }

    private static function sameUrl(int|string|bool|null $live, string $after, string $page): bool
    {
        if (!is_string($live)) {
            return false;
        }
        $absolute = static function (string $url) use ($page): string {
            if (str_starts_with($url, '/') && preg_match('#^(https?://[^/]+)#i', $page, $m) === 1) {
                $url = $m[1] . $url;
            }
            return rtrim(strtolower($url), '/');
        };

        return $absolute(trim($live)) === $absolute($after);
    }

    private static function squash(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5)));
    }

    private static function text(int|string|bool|null $value): ?string
    {
        return $value === null ? null : mb_substr(self::display($value), 0, 300);
    }
}

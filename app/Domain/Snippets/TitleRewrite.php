<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Snippets;

/**
 * Compares a page's own title with the title Google showed for it in a
 * results page (1.7.0). Google rewrites titles it finds too long, stuffed,
 * vague or unlike the page; a rewrite is a hint, not an error.
 */
final class TitleRewrite
{
    /** Google showed the page's title as written. */
    public const string KEPT = 'kept';
    /** Google showed the beginning of the title and cut the rest. */
    public const string CUT = 'cut';
    /** Only the site name part changed, was added or was dropped. */
    public const string SITE_NAME = 'site_name';
    /** Google wrote a different title. */
    public const string REWRITTEN = 'rewritten';
    /** The page has no title, so Google wrote one. */
    public const string MISSING = 'missing';

    /**
     * @return array{kind: string, overlap: float} overlap: the share of the shown title's words taken from the page's title
     */
    public static function compare(?string $own, string $shown): array
    {
        $shownNorm = self::normalize($shown);
        if ($own === null || trim($own) === '') {
            return ['kind' => self::MISSING, 'overlap' => 0.0];
        }
        $ownNorm = self::normalize($own);
        if ($ownNorm === $shownNorm) {
            return ['kind' => self::KEPT, 'overlap' => 1.0];
        }
        $cut = (string) preg_replace('/\s*(\.\.\.|…)$/u', '', $shownNorm);
        if ($cut !== $shownNorm && $cut !== '' && str_starts_with($ownNorm, $cut)) {
            return ['kind' => self::CUT, 'overlap' => 1.0];
        }
        $main = self::segments($ownNorm)[0];
        if ($main !== '' && in_array($main, self::segments($cut), true)) {
            return ['kind' => self::SITE_NAME, 'overlap' => self::overlap($ownNorm, $cut)];
        }

        return ['kind' => self::REWRITTEN, 'overlap' => self::overlap($ownNorm, $cut)];
    }

    /** Lower case, decoded entities, one kind of separator and single spaces. */
    public static function normalize(string $title): string
    {
        $title = mb_strtolower(html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = (string) preg_replace('/\s*[|·•»]\s*|\s+[-–—]\s+|:\s+/u', ' | ', $title);

        return trim((string) preg_replace('/\s+/u', ' ', $title));
    }

    /** @return non-empty-list<string> */
    private static function segments(string $normalized): array
    {
        return array_map('trim', explode(' | ', $normalized));
    }

    private static function overlap(string $own, string $shown): float
    {
        $words = static fn(string $s): array => array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $s) ?: [], static fn(string $w): bool => $w !== '')));
        $shownWords = $words($shown);
        if ($shownWords === []) {
            return 0.0;
        }

        return round(count(array_intersect($shownWords, $words($own))) / count($shownWords), 2);
    }
}

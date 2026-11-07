<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Keywords;

use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Keyword text normalization and validation. Keywords are stored in lower
 * case with single spaces, so "Red  Shoes" and "red shoes" are the same.
 * The limits match Google Ads keyword rules used by metrics providers.
 */
final class Keyword
{
    public const int MAX_LENGTH = 80;
    public const int MAX_WORDS = 10;
    private const string FORBIDDEN = '!@%^()={};~`<>?\\|,';

    private function __construct() {}

    /**
     * Lower case, single spaces and one Unicode form for every script
     * (0.44.3): text is composed to NFC, so "é" typed as one character and as
     * "e" plus an accent is the same keyword; non-breaking, ideographic and
     * other Unicode spaces count as spaces; zero-width characters and byte
     * order marks from copy and paste are removed. Invalid UTF-8 bytes become
     * "?", which validation then refuses.
     */
    public static function normalize(string $keyword): string
    {
        if (!mb_check_encoding($keyword, 'UTF-8')) {
            $keyword = mb_scrub($keyword, 'UTF-8');
        }
        if (class_exists(\Normalizer::class)) {
            $keyword = \Normalizer::normalize($keyword, \Normalizer::FORM_C) ?: $keyword;
        }
        $keyword = (string) preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $keyword);
        $keyword = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $keyword);

        return mb_strtolower(trim($keyword));
    }

    /**
     * SHA-256 of the normalized keyword (32 raw bytes), used as lookup key.
     */
    public static function hash(string $normalized): string
    {
        return hash('sha256', $normalized, true);
    }

    /**
     * Returns an error message, or null when the normalized keyword is valid.
     */
    public static function problem(string $normalized): ?string
    {
        if ($normalized === '') {
            return 'is empty';
        }
        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            return sprintf('is longer than %d characters', self::MAX_LENGTH);
        }
        if (count(explode(' ', $normalized)) > self::MAX_WORDS) {
            return sprintf('has more than %d words', self::MAX_WORDS);
        }
        if (strpbrk($normalized, self::FORBIDDEN) !== false || preg_match('/[\x00-\x1f\x7f]/', $normalized) === 1) {
            return 'contains characters that search engines do not accept in keywords';
        }

        return null;
    }

    /**
     * Parses a textarea (one keyword per line), normalizes and de-duplicates.
     *
     * @return list<string>
     * @throws ValidationException listing the invalid lines
     */
    public static function parseList(string $text, int $maxCount): array
    {
        $keywords = [];
        $invalid = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $normalized = self::normalize($line);
            if ($normalized === '') {
                continue;
            }
            $problem = self::problem($normalized);
            if ($problem !== null) {
                $invalid[] = sprintf('"%s" %s', mb_substr($normalized, 0, 90), $problem);
                continue;
            }
            $keywords[$normalized] = true;
        }

        if ($invalid !== []) {
            throw new ValidationException(['keywords' => 'Please fix these keywords: ' . implode('; ', array_slice($invalid, 0, 5)) . (count($invalid) > 5 ? sprintf(' (and %d more)', count($invalid) - 5) : '') . '.']);
        }
        if ($keywords === []) {
            throw new ValidationException(['keywords' => 'Enter at least one keyword, one per line.']);
        }
        if (count($keywords) > $maxCount) {
            throw new ValidationException(['keywords' => sprintf('Enter at most %d keywords at once.', $maxCount)]);
        }

        return array_map('strval', array_keys($keywords));
    }
}

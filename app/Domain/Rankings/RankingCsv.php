<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Reads keyword and ranking exports from other tools (1.3.0) so people who
 * switch keep their history: Ahrefs (UTF-16, tab-separated), Semrush,
 * Search Console, Moz and plain spreadsheets. Columns are found by their
 * header names; the delimiter (comma, semicolon or tab) and encoding
 * (UTF-8, UTF-16 with byte order mark, or Windows-1252) are detected.
 *
 * Two layouts are read:
 * - long: one row per keyword (and date), with Keyword, Position and
 *   optionally Date, URL and Device columns;
 * - wide: one row per keyword with one column per date (Semrush Position
 *   Tracking), each holding the position on that date.
 * A file with a Keyword column only is a keyword list.
 *
 * Positions 1 to 100 are kept; empty, "-", "0", ">100" and similar mean
 * "not in the top 100" and are kept as not ranked.
 */
final class RankingCsv
{
    public const int MAX_LINES = 100000;
    private const array KEYWORD = ['keyword', 'keywords', 'query', 'queries', 'top queries', 'search term', 'search query', 'term', 'keyphrase'];
    private const array POSITION = ['position', 'rank', 'current position', 'current rank', 'pos', 'pos.', 'google position', 'ranking', 'google rank', 'organic position'];
    private const array DATE = ['date', 'day', 'checked', 'checked on', 'date checked', 'updated', 'last update', 'last updated'];
    private const array URL = ['url', 'current url', 'ranking url', 'landing page', 'page', 'target url', 'top page'];
    private const array DEVICE = ['device'];

    /**
     * @return array{
     *     layout: 'long'|'wide'|'keywords',
     *     rows: list<array{keyword: string, date: ?string, position: ?int, url: ?string, device: ?string}>,
     *     lines: int,
     *     columns: array<string, string>
     * } columns names the header used for each field
     * @throws \InvalidArgumentException when no keyword column is found
     */
    public static function parse(string $bytes): array
    {
        $text = self::utf8($bytes);
        $lines = preg_split('/\r\n|\n|\r/', $text) ?: [];
        $lines = array_values(array_filter(array_slice($lines, 0, self::MAX_LINES + 1), static fn(string $l): bool => trim($l) !== ''));
        if ($lines === []) {
            throw new \InvalidArgumentException('The file is empty.');
        }
        $delimiter = self::delimiter($lines[0]);
        $header = array_map(static fn(?string $h): string => self::header((string) $h), str_getcsv($lines[0], $delimiter, '"', ''));
        $find = static function (array $names) use ($header): ?int {
            foreach ($header as $i => $h) {
                if (in_array($h, $names, true)) {
                    return $i;
                }
            }

            return null;
        };
        $keyword = $find(self::KEYWORD);
        if ($keyword === null) {
            throw new \InvalidArgumentException('No keyword column found. The first row must name the columns, such as Keyword, Position, Date and URL.');
        }
        $position = $find(self::POSITION);
        $date = $find(self::DATE);
        $url = $find(self::URL);
        $device = $find(self::DEVICE);
        $dateColumns = [];
        foreach ($header as $i => $h) {
            if ($i !== $keyword && ($d = self::date($h)) !== null) {
                $dateColumns[$i] = $d;
            }
        }
        $layout = $position === null && count($dateColumns) >= 1 ? 'wide' : ($position === null ? 'keywords' : 'long');
        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');
            $term = trim((string) ($cells[$keyword] ?? ''));
            if ($term === '') {
                continue;
            }
            $rowUrl = $url !== null ? self::url((string) ($cells[$url] ?? '')) : null;
            $rowDevice = $device !== null ? self::device((string) ($cells[$device] ?? '')) : null;
            if ($layout === 'wide') {
                foreach ($dateColumns as $i => $d) {
                    $rows[] = ['keyword' => $term, 'date' => $d, 'position' => self::position((string) ($cells[$i] ?? '')), 'url' => $rowUrl, 'device' => $rowDevice];
                }
                continue;
            }
            $rows[] = [
                'keyword' => $term,
                'date' => $date !== null ? self::date((string) ($cells[$date] ?? '')) : null,
                'position' => $position !== null ? self::position((string) ($cells[$position] ?? '')) : null,
                'url' => $rowUrl,
                'device' => $rowDevice,
            ];
        }
        $columns = ['keyword' => $header[$keyword]];
        foreach (['position' => $position, 'date' => $date, 'url' => $url, 'device' => $device] as $field => $i) {
            if ($i !== null) {
                $columns[$field] = $header[$i];
            }
        }
        if ($layout === 'wide') {
            $columns['dates'] = count($dateColumns) . ' date columns';
        }

        return ['layout' => $layout, 'rows' => $rows, 'lines' => count($lines) - 1, 'columns' => $columns];
    }

    /** A position from 1 to 100, or null for anything that means "not ranked". */
    public static function position(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^\d+(?:[.,]0+)?$/', $value) !== 1) {
            return null;
        }
        $position = (int) $value;

        return $position >= 1 && $position <= 100 ? $position : null;
    }

    /** A date as Y-m-d, from ISO, compact, European, US or written formats; null otherwise. */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // Semrush and others write "20261001" or put the date first, as in "2026-10-01 (desktop)".
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})(?:$|[\sT_(])/', $value, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return sprintf('%s-%s-%s', $m[1], $m[2], $m[3]);
        }
        foreach (['!d.m.Y', '!d/m/Y', '!m/d/Y', '!M j, Y', '!j M Y', '!F j, Y'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed !== false && \DateTimeImmutable::getLastErrors() === false) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    /** Converts UTF-16 (with byte order mark) and Windows-1252 to UTF-8 and drops a UTF-8 byte order mark. */
    private static function utf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $bytes = substr($bytes, 3);
        }

        return mb_check_encoding($bytes, 'UTF-8') ? $bytes : (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    private static function delimiter(string $header): string
    {
        $counts = ["\t" => substr_count($header, "\t"), ';' => substr_count($header, ';'), ',' => substr_count($header, ',')];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private static function header(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', trim($value, " \t\"'\u{FEFF}"))));
    }

    private static function url(string $value): ?string
    {
        $value = trim($value);

        return preg_match('#^https?://\S+$#i', $value) === 1 && strlen($value) <= 2048 ? $value : null;
    }

    private static function device(string $value): ?string
    {
        $value = strtolower(trim($value));

        return match (true) {
            str_contains($value, 'mobile') || str_contains($value, 'phone') => 'mobile',
            str_contains($value, 'desktop') || str_contains($value, 'computer') => 'desktop',
            default => null,
        };
    }
}

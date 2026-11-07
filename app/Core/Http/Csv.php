<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http;

/**
 * CSV for downloads: UTF-8 with BOM (so Excel detects the encoding), comma
 * separated, CRLF line endings. Text cells that a spreadsheet would treat as
 * a formula (=, +, -, @, tab, CR) are prefixed with an apostrophe (CSV
 * injection); numbers are written as they are.
 */
final class Csv
{
    private function __construct() {}

    /**
     * @param list<list<string|int|float|bool|null>> $rows the first row is the header
     */
    public static function encode(array $rows): string
    {
        return "\u{FEFF}" . self::rows($rows);
    }

    /**
     * Rows without the byte-order mark, for appending to a CSV built in chunks.
     *
     * @param list<list<string|int|float|bool|null>> $rows
     */
    public static function rows(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not create the CSV buffer.');
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), ',', '"', '', "\r\n");
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * A safe download name such as "acme-shop-keywords-2026-10-02.csv".
     */
    public static function fileName(string $project, string $kind, string $date): string
    {
        // Some iconv builds transliterate "é" as "'e" or "ü" as "\"u"; drop those marks.
        $ascii = (string) preg_replace('/[\'"`^~]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $project));
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)), '-');

        return ($slug !== '' ? substr($slug, 0, 60) . '-' : '') . $kind . '-' . $date . '.csv';
    }

    private static function cell(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_int($value) => (string) $value,
            is_float($value) => rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.'),
            preg_match('/^[=+\-@\t\r]/', $value) === 1 => "'" . $value,
            default => $value,
        };
    }
}

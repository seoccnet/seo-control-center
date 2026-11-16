<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Snippets;

/**
 * How wide Google draws a title or description (1.6.0). Google cuts
 * search snippets by pixel width, not by characters: a title of narrow
 * letters fits more characters than one of capitals. Widths use Arial's
 * advance widths, the font Google's results use, at 20 px for titles and
 * 14 px for descriptions on desktop. The limits are the commonly measured
 * ones; Google changes its layout now and then, so read results near the
 * limit as "about to be cut".
 */
final class SnippetWidth
{
    public const int TITLE_PX = 600;
    public const int TITLE_FONT = 20;
    public const int DESCRIPTION_PX = 920;
    public const int DESCRIPTION_FONT = 14;
    private const string ELLIPSIS = ' ...';

    /** Arial advance widths in thousandths of the font size, printable ASCII. */
    private const array ARIAL = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333,
        '*' => 389, '+' => 584, ',' => 278, '-' => 333, '.' => 278, '/' => 278, '0' => 556, '1' => 556, '2' => 556, '3' => 556,
        '4' => 556, '5' => 556, '6' => 556, '7' => 556, '8' => 556, '9' => 556, ':' => 278, ';' => 278, '<' => 584, '=' => 584,
        '>' => 584, '?' => 556, '@' => 1015, 'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778,
        'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556, 'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778,
        'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667, 'Y' => 667, 'Z' => 611, '[' => 278,
        '\\' => 278, ']' => 278, '^' => 469, '_' => 556, '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556, 'e' => 556,
        'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556,
        'p' => 556, 'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500,
        'z' => 500, '{' => 334, '|' => 260, '}' => 334, '~' => 584,
    ];

    /** Width in pixels of $text at $fontPx. */
    public static function width(string $text, int $fontPx): float
    {
        $units = 0;
        foreach (mb_str_split(self::clean($text)) as $char) {
            $units += self::units($char);
        }

        return round($units * $fontPx / 1000, 1);
    }

    /**
     * The text as Google would show it: whole words up to the limit, then
     * " ...". Unchanged when it fits.
     */
    public static function truncate(string $text, int $maxPx, int $fontPx): string
    {
        $text = self::clean($text);
        if (self::width($text, $fontPx) <= $maxPx) {
            return $text;
        }
        $room = $maxPx - self::width(self::ELLIPSIS, $fontPx);
        $out = '';
        foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
            if (self::width($out . $part, $fontPx) > $room) {
                break;
            }
            $out .= $part;
        }
        if (trim($out) === '') {
            // One long word: cut by characters.
            foreach (mb_str_split($text) as $char) {
                if (self::width($out . $char, $fontPx) > $room) {
                    break;
                }
                $out .= $char;
            }
        }

        return rtrim($out, " \t,;:-–") . self::ELLIPSIS;
    }

    /**
     * @return array{width: float, limit: int, fits: bool, shown: string}
     */
    public static function title(string $title): array
    {
        $width = self::width($title, self::TITLE_FONT);

        return ['width' => $width, 'limit' => self::TITLE_PX, 'fits' => $width <= self::TITLE_PX, 'shown' => self::truncate($title, self::TITLE_PX, self::TITLE_FONT)];
    }

    /**
     * @return array{width: float, limit: int, fits: bool, shown: string}
     */
    public static function description(string $description): array
    {
        $width = self::width($description, self::DESCRIPTION_FONT);

        return ['width' => $width, 'limit' => self::DESCRIPTION_PX, 'fits' => $width <= self::DESCRIPTION_PX, 'shown' => self::truncate($description, self::DESCRIPTION_PX, self::DESCRIPTION_FONT)];
    }

    private static function units(string $char): int
    {
        if (isset(self::ARIAL[$char])) {
            return self::ARIAL[$char];
        }
        $base = class_exists(\Normalizer::class) ? mb_substr((string) \Normalizer::normalize($char, \Normalizer::FORM_D), 0, 1) : $char;
        if (isset(self::ARIAL[$base])) {
            return self::ARIAL[$base];
        }
        if (preg_match('/[\x{1100}-\x{11FF}\x{2E80}-\x{9FFF}\x{AC00}-\x{D7AF}\x{F900}-\x{FAFF}\x{FF00}-\x{FFEF}]/u', $char) === 1) {
            return 1000;
        }
        if (in_array($char, ['–', '—', '…', '€', '©', '®', '™', '•', '·', '|', '«', '»', '„', '“', '”', '‘', '’'], true)) {
            return match ($char) {
                '—' => 1000, '…', '€', '©', '®' => 737, '™' => 1000, '–' => 556, '•' => 350, '·' => 278, '«', '»' => 556, default => 333
            };
        }

        return preg_match('/\p{Lu}/u', $char) === 1 ? 700 : 556;
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}

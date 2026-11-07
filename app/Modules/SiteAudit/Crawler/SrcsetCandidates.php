<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

/** Passive URL extraction; does not validate descriptors or select an image. */
final class SrcsetCandidates
{
    /** @return list<string> */
    public static function urls(string $input): array
    {
        $length = strlen($input);
        if ($length > 2048) {
            return [];
        }
        $position = 0;
        $urls = [];
        $space = " \t\n\r\f";
        while ($position < $length && count($urls) < 40) {
            $position += strspn($input, $space . ',', $position);
            $start = $position;
            $position += strcspn($input, $space, $position);
            $url = substr($input, $start, $position - $start);
            if ($url === '') {
                break;
            }
            $urls[] = rtrim($url, ',');
            if (str_ends_with($url, ',')) {
                continue;
            }
            // Commas inside URL tokens or parenthesized descriptors are not separators.
            $depth = 0;
            while ($position < $length) {
                $character = $input[$position++];
                if ($character === '(') {
                    ++$depth;
                } elseif ($character === ')' && $depth > 0) {
                    --$depth;
                } elseif ($character === ',' && $depth === 0) {
                    break;
                }
            }
        }
        return $urls;
    }
}

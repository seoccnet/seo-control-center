<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Clusters;

/**
 * The page a URL belongs to for topic ownership and overlap: pagination
 * variants (?page=2, ?p=3, /page/4/) are one listing, not competing pages.
 * Other query parameters stay, because they can select different content.
 */
final class PageKey
{
    /** Query parameters that only select a page of the same listing. */
    public const array PAGINATION = ['page', 'p', 'pg', 'paged', 'pagenum', 'page_no', 'pageno'];

    public static function of(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }
        $path = (string) ($parts['path'] ?? '/');
        // WordPress-style /page/4/ at the end of the path.
        $path = (string) preg_replace('#/page/\d+/?$#D', '/', $path);
        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $kept = [];
            foreach (explode('&', $parts['query']) as $pair) {
                $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
                if (!in_array($name, self::PAGINATION, true)) {
                    $kept[] = $pair;
                }
            }
            $query = $kept === [] ? '' : '?' . implode('&', $kept);
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($path === '' ? '/' : $path) . $query;
    }
}

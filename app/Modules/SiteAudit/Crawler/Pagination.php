<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

/**
 * Recognises paginated listings (?page=2, ?p=3, ?paged=4, …), so a long
 * series does not use up the audit's page limit and its pages, which
 * normally share a title, count as one page in the duplicate checks.
 */
final class Pagination
{
    /** Pages of one series that the crawl follows. */
    public const int MAX_PAGE = 10;
    private const array PAGE_PARAMS = ['page', 'p', 'paged', 'pg', 'pagenum'];
    private const array OFFSET_PARAMS = ['start', 'offset'];

    /**
     * The page number when $url is a paginated address, otherwise null.
     */
    public static function pageNumber(string $url): ?int
    {
        foreach (self::query($url) as $name => $value) {
            if (in_array(strtolower((string) $name), self::PAGE_PARAMS, true) && is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * Whether the crawl should follow a link to $url.
     */
    public static function follow(string $url): bool
    {
        $page = self::pageNumber($url);

        return $page === null || $page <= self::MAX_PAGE;
    }

    /**
     * $url without its page and offset parameters: the same for every page of a series.
     */
    public static function series(string $url): string
    {
        $query = self::query($url);
        if ($query === []) {
            return $url;
        }
        $kept = array_filter($query, static fn(mixed $v, int|string $k): bool => !in_array(strtolower((string) $k), [...self::PAGE_PARAMS, ...self::OFFSET_PARAMS], true), ARRAY_FILTER_USE_BOTH);
        $base = explode('?', $url, 2)[0];

        return $kept === [] ? $base : $base . '?' . http_build_query($kept);
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function query(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return [];
        }
        parse_str($query, $params);

        return $params;
    }
}

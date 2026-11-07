<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Api;

use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Modules\Bing\BingCrawlDay;
use SeoControlCenter\Modules\Bing\BingLinkCount;
use SeoControlCenter\Modules\Bing\BingWeeklyRow;

/**
 * Bing Webmaster Tools API (JSON endpoint, API key authentication).
 *
 * - GetUserSites: the sites of the key's account;
 * - GetRankAndTrafficStats: clicks and impressions per day;
 * - GetQueryStats / GetPageStats: queries and pages in weekly buckets,
 *   with the average position over impressions;
 * - GetCrawlStats: pages crawled, crawl errors and pages in the index per day;
 * - GetLinkCounts: inbound links per page of the site (paged).
 *
 * Each call returns everything Bing has, without date parameters. Dates are
 * WCF JSON dates ("/Date(1316156400000-0700)/"), read as Pacific Time days
 * like Bing's own reports.
 */
final readonly class BingWebmasterClient
{
    public const string BASE = 'https://ssl.bing.com/webmaster/api.svc/json/';
    private const array HOSTS = ['ssl.bing.com'];
    /** Rows kept per weekly bucket and dimension (by clicks, then impressions). */
    public const int MAX_ROWS_PER_WEEK = 1000;
    /** Result pages of GetLinkCounts read per import. */
    public const int MAX_LINK_PAGES = 5;

    public function __construct(private HttpClient $http) {}

    /**
     * Verified sites of the account, sorted.
     *
     * @return list<string>
     * @throws IntegrationException
     */
    public function sites(#[SensitiveParameter] string $apiKey): array
    {
        $sites = [];
        foreach ($this->call('GetUserSites', $apiKey, []) as $site) {
            if (is_array($site) && is_string($site['Url'] ?? null) && ($site['IsVerified'] ?? false) === true) {
                $sites[] = $site['Url'];
            }
        }
        $sites = array_values(array_unique($sites));
        sort($sites);

        return $sites;
    }

    /**
     * @return list<DailyMetrics> sorted by date, position 0 (not reported per day)
     * @throws IntegrationException
     */
    public function daily(#[SensitiveParameter] string $apiKey, string $siteUrl): array
    {
        $totals = [];
        foreach ($this->call('GetRankAndTrafficStats', $apiKey, ['siteUrl' => $siteUrl]) as $row) {
            $date = is_array($row) ? self::date($row['Date'] ?? null) : null;
            if ($date === null) {
                continue;
            }
            $totals[$date] ??= [0, 0];
            $totals[$date][0] += self::count($row['Clicks'] ?? 0);
            $totals[$date][1] += self::count($row['Impressions'] ?? 0);
        }
        ksort($totals);
        $days = [];
        foreach ($totals as $date => [$clicks, $impressions]) {
            $days[] = new DailyMetrics((string) $date, new Metrics($clicks, $impressions, 0.0));
        }

        return $days;
    }

    /**
     * @return list<BingWeeklyRow>
     * @throws IntegrationException
     */
    public function weekly(#[SensitiveParameter] string $apiKey, string $siteUrl, PerformanceDimension $dimension): array
    {
        $method = $dimension === PerformanceDimension::Page ? 'GetPageStats' : 'GetQueryStats';
        /** @var array<string, array<string, array{0: int, 1: int, 2: float}>> $weeks week => key => [clicks, impressions, position × impressions] */
        $weeks = [];
        foreach ($this->call($method, $apiKey, ['siteUrl' => $siteUrl]) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $week = self::date($row['Date'] ?? null);
            $key = is_string($row['Query'] ?? null) ? trim($row['Query']) : '';
            if ($week === null || $key === '') {
                continue;
            }
            $impressions = self::count($row['Impressions'] ?? 0);
            $position = is_int($row['AvgImpressionPosition'] ?? null) || is_float($row['AvgImpressionPosition'] ?? null) ? max(0.0, (float) $row['AvgImpressionPosition']) : 0.0;
            $weeks[$week][$key] ??= [0, 0, 0.0];
            $weeks[$week][$key][0] += self::count($row['Clicks'] ?? 0);
            $weeks[$week][$key][1] += $impressions;
            $weeks[$week][$key][2] += $position * $impressions;
        }

        $rows = [];
        foreach ($weeks as $week => $keys) {
            uasort($keys, static fn(array $a, array $b): int => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
            foreach (array_slice($keys, 0, self::MAX_ROWS_PER_WEEK, true) as $key => [$clicks, $impressions, $weighted]) {
                $rows[] = new BingWeeklyRow((string) $week, $dimension, new DimensionRow((string) $key, new Metrics($clicks, $impressions, $impressions > 0 ? $weighted / $impressions : 0.0)));
            }
        }

        return $rows;
    }

    /**
     * Daily crawl and index counts. Bing may report a day more than once; the last row wins.
     *
     * @return list<BingCrawlDay> sorted by date
     * @throws IntegrationException
     */
    public function crawlStats(#[SensitiveParameter] string $apiKey, string $siteUrl): array
    {
        $days = [];
        foreach ($this->call('GetCrawlStats', $apiKey, ['siteUrl' => $siteUrl]) as $row) {
            $date = is_array($row) ? self::date($row['Date'] ?? null) : null;
            if ($date === null) {
                continue;
            }
            $days[$date] = new BingCrawlDay(
                $date,
                self::count($row['CrawledPages'] ?? 0),
                self::count($row['CrawlErrors'] ?? 0),
                self::count($row['InIndex'] ?? 0),
                self::count($row['InLinks'] ?? 0),
                self::count($row['Code2xx'] ?? 0),
                self::count($row['Code301'] ?? 0) + self::count($row['Code302'] ?? 0),
                self::count($row['Code4xx'] ?? 0),
                self::count($row['Code5xx'] ?? 0),
                self::count($row['BlockedByRobotsTxt'] ?? 0),
                self::count($row['AllOtherCodes'] ?? 0),
            );
        }
        ksort($days);

        return array_values($days);
    }

    /**
     * Pages of the site with the number of inbound links Bing knows, most linked
     * first, reading at most $maxPages result pages.
     *
     * @return list<BingLinkCount>
     * @throws IntegrationException
     */
    public function linkCounts(#[SensitiveParameter] string $apiKey, string $siteUrl, int $maxPages = self::MAX_LINK_PAGES): array
    {
        $counts = [];
        for ($page = 0; $page < $maxPages; ++$page) {
            $data = $this->raw('GetLinkCounts', $apiKey, ['siteUrl' => $siteUrl, 'page' => (string) $page]);
            if (!is_array($data)) {
                break;
            }
            foreach (is_array($data['Links'] ?? null) ? $data['Links'] : [] as $link) {
                if (is_array($link) && is_string($link['Url'] ?? null) && preg_match('#^https?://#i', $link['Url']) === 1) {
                    $url = mb_substr($link['Url'], 0, 2048);
                    $counts[$url] = max($counts[$url] ?? 0, self::count($link['Count'] ?? 0));
                }
            }
            $totalPages = is_int($data['TotalPages'] ?? null) ? $data['TotalPages'] : 0;
            if ($page + 1 >= $totalPages) {
                break;
            }
        }
        arsort($counts);
        $result = [];
        foreach ($counts as $url => $links) {
            $result[] = new BingLinkCount((string) $url, $links);
        }

        return $result;
    }

    /**
     * WCF JSON date to a Pacific Time day, e.g. "/Date(1316156400000-0700)/" → 2011-09-16.
     */
    public static function date(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('#^/Date\((-?\d+)([+-]\d{4})?\)/$#', $value, $m) !== 1) {
            return null;
        }

        return new DateTimeImmutable('@' . intdiv((int) $m[1], 1000))->setTimezone(new DateTimeZone('America/Los_Angeles'))->format('Y-m-d');
    }

    /**
     * @param array<string, string> $query
     * @return list<mixed> the "d" array of the response
     * @throws IntegrationException
     */
    private function call(string $method, #[SensitiveParameter] string $apiKey, array $query): array
    {
        $list = $this->raw($method, $apiKey, $query);
        if ($list === null) {
            return [];
        }
        if (!is_array($list)) {
            throw new IntegrationException('Bing Webmaster Tools returned an unexpected response.', true);
        }

        return array_values($list);
    }

    /**
     * @param array<string, string> $query
     * @return mixed the "d" member of the response
     * @throws IntegrationException
     */
    private function raw(string $method, #[SensitiveParameter] string $apiKey, array $query): mixed
    {
        $url = self::BASE . $method . '?' . http_build_query($query + ['apikey' => $apiKey], '', '&', PHP_QUERY_RFC3986);
        try {
            $response = $this->http->send('GET', $url, ['Accept' => 'application/json'], null, self::HOSTS);
        } catch (HttpClientException $e) {
            throw new IntegrationException('Bing Webmaster Tools could not be reached: ' . $e->getMessage(), $e->retryable);
        }
        if (!$response->isSuccess()) {
            throw self::error($response);
        }
        $data = $response->json();

        return is_array($data) ? ($data['d'] ?? null) : null;
    }

    private static function error(HttpResponse $response): IntegrationException
    {
        $data = $response->json() ?? [];
        $message = is_string($data['Message'] ?? null) ? $data['Message'] : '';
        if ($response->status === 429 || $response->status >= 500) {
            return new IntegrationException('Bing Webmaster Tools is busy (HTTP ' . $response->status . '). The import tries again later.', true, retryAfterSeconds: $response->retryAfterSeconds);
        }
        if ($response->status === 401 || $response->status === 403 || stripos($message, 'apikey') !== false || stripos($message, 'NotAuthorized') !== false) {
            return new IntegrationException('Bing rejected the API key. Create a new one in Bing Webmaster Tools (Settings → API Access) and enter it under Integrations → Bing.', false, true);
        }

        return new IntegrationException('Bing Webmaster Tools refused the request' . ($message !== '' ? ': ' . mb_substr($message, 0, 200) : ' (HTTP ' . $response->status . ').'), false);
    }

    private static function count(mixed $value): int
    {
        return is_int($value) || is_float($value) ? max(0, (int) $value) : 0;
    }
}

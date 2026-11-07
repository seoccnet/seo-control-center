<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Google;

use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\FreshDay;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\QueryPageRow;
use SeoControlCenter\Domain\SearchConsole\SearchConsoleProperty;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceProvider;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\SearchConsole\Sitemap;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Google Search Console API adapter (webmasters v3). Converts Google's
 * responses into provider-independent objects.
 */
final readonly class GoogleSearchConsoleApi implements SearchPerformanceProvider
{
    public const string BASE = 'https://www.googleapis.com/webmasters/v3';
    /** Google's maximum rows per Search Analytics request. */
    public const int PAGE_SIZE = 25000;
    private const array HOSTS = ['www.googleapis.com'];

    public function __construct(
        private HttpClient $http,
        private GoogleAccessTokens $tokens,
    ) {}

    public function listProperties(Connection $connection): array
    {
        $data = $this->get($connection, self::BASE . '/sites')->json() ?? [];
        $entries = is_array($data['siteEntry'] ?? null) ? $data['siteEntry'] : [];

        $properties = [];
        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['siteUrl'] ?? null) && is_string($entry['permissionLevel'] ?? null)) {
                $properties[] = new SearchConsoleProperty($entry['siteUrl'], $entry['permissionLevel']);
            }
        }
        usort($properties, static fn(SearchConsoleProperty $a, SearchConsoleProperty $b): int => strcmp($a->label(), $b->label()));

        return $properties;
    }

    public function dailyTotals(Connection $connection, string $siteUrl, DateRange $range): array
    {
        $days = [];
        foreach ($this->query($connection, $siteUrl, $range->fromString(), $range->toString(), 'date', self::PAGE_SIZE) as [$key, $metrics]) {
            $days[] = new DailyMetrics($key, $metrics);
        }
        usort($days, static fn(DailyMetrics $a, DailyMetrics $b): int => strcmp($a->date, $b->date));

        return $days;
    }

    public function freshDailyTotals(Connection $connection, string $siteUrl, DateRange $range): array
    {
        $body = json_encode([
            'startDate' => $range->fromString(),
            'endDate' => $range->toString(),
            'dimensions' => ['date'],
            'type' => 'web',
            'dataState' => 'all',
            'rowLimit' => 1000,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $data = $this->send($connection, 'POST', self::BASE . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query', $body)->json() ?? [];
        // Google names the first day it is still collecting data for; that day and later ones are incomplete.
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $incompleteFrom = is_string($metadata['first_incomplete_date'] ?? null) ? $metadata['first_incomplete_date'] : null;
        $days = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            $parsed = self::row($row);
            if ($parsed !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $parsed[0]) === 1) {
                $days[] = new FreshDay($parsed[0], $parsed[1], $incompleteFrom === null || $parsed[0] < $incompleteFrom);
            }
        }
        usort($days, static fn(FreshDay $a, FreshDay $b): int => strcmp($a->date, $b->date));

        return $days;
    }

    public function searchTypeDailyTotals(Connection $connection, string $siteUrl, SearchType $type, DateRange $range): array
    {
        $days = [];
        foreach ($this->query($connection, $siteUrl, $range->fromString(), $range->toString(), 'date', self::PAGE_SIZE, $type->value) as [$key, $metrics]) {
            $days[] = new DailyMetrics($key, $type->hasPosition() ? $metrics : new Metrics($metrics->clicks, $metrics->impressions, 0.0));
        }
        usort($days, static fn(DailyMetrics $a, DailyMetrics $b): int => strcmp($a->date, $b->date));

        return $days;
    }

    public function sitemaps(Connection $connection, string $siteUrl): array
    {
        $data = $this->get($connection, self::BASE . '/sites/' . rawurlencode($siteUrl) . '/sitemaps')->json() ?? [];
        $sitemaps = [];
        foreach (is_array($data['sitemap'] ?? null) ? $data['sitemap'] : [] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || preg_match('#^https?://#i', $entry['path']) !== 1) {
                continue;
            }
            $submitted = 0;
            foreach (is_array($entry['contents'] ?? null) ? $entry['contents'] : [] as $content) {
                $submitted += is_array($content) ? self::int($content['submitted'] ?? 0) : 0;
            }
            $sitemaps[] = new Sitemap(
                mb_substr($entry['path'], 0, 2048),
                ($entry['isSitemapsIndex'] ?? false) === true,
                ($entry['isPending'] ?? false) === true,
                self::timestamp($entry['lastSubmitted'] ?? null),
                self::timestamp($entry['lastDownloaded'] ?? null),
                self::int($entry['errors'] ?? 0),
                self::int($entry['warnings'] ?? 0),
                $submitted,
            );
        }

        return $sitemaps;
    }

    /** Google sends 64-bit counts as strings. */
    private static function int(mixed $value): int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? max(0, (int) $value) : 0;
    }

    /** RFC 3339 timestamp to UTC 'Y-m-d H:i:s'. */
    private static function timestamp(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    public function dayBreakdown(Connection $connection, string $siteUrl, string $date, PerformanceDimension $dimension, int $maxRows): array
    {
        $rows = [];
        foreach ($this->query($connection, $siteUrl, $date, $date, $dimension->value, $maxRows) as [$key, $metrics]) {
            $rows[] = new DimensionRow($dimension === PerformanceDimension::Country ? strtolower($key) : $key, $metrics);
        }

        return $rows;
    }

    /**
     * Search Analytics query for one dimension, paginated up to $maxRows.
     * Google returns rows ordered by clicks (highest first).
     *
     * @return list<array{0: string, 1: Metrics}>
     */
    private function query(Connection $connection, string $siteUrl, string $from, string $to, string $dimension, int $maxRows, string $type = 'web'): array
    {
        $url = self::BASE . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query';
        $maxRows = max(1, $maxRows);
        $result = [];
        for ($startRow = 0; $startRow < $maxRows; $startRow += self::PAGE_SIZE) {
            $pageSize = min(self::PAGE_SIZE, $maxRows - $startRow);
            $body = json_encode([
                'startDate' => $from,
                'endDate' => $to,
                'dimensions' => [$dimension],
                'type' => $type,
                'dataState' => 'final',
                'rowLimit' => $pageSize,
                'startRow' => $startRow,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $data = $this->send($connection, 'POST', $url, $body)->json() ?? [];
            $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
            foreach ($rows as $row) {
                $parsed = self::row($row);
                if ($parsed !== null) {
                    $result[] = $parsed;
                }
            }
            if (count($rows) < $pageSize) {
                break;
            }
        }

        return $result;
    }

    /**
     * @return array{0: string, 1: Metrics}|null
     */
    private static function row(mixed $row): ?array
    {
        if (!is_array($row) || !is_array($row['keys'] ?? null) || !is_string($row['keys'][0] ?? null)) {
            return null;
        }
        $clicks = $row['clicks'] ?? 0;
        $impressions = $row['impressions'] ?? 0;
        $position = $row['position'] ?? 0;
        if (!is_numeric($clicks) || !is_numeric($impressions) || !is_numeric($position)) {
            return null;
        }

        return [$row['keys'][0], new Metrics((int) round((float) $clicks), (int) round((float) $impressions), max(0.0, round((float) $position, 2)))];
    }

    public function dayQueryPages(Connection $connection, string $siteUrl, string $date, int $maxRows): array
    {
        $result = [];
        $maxRows = max(1, $maxRows);
        for ($start = 0; $start < $maxRows; $start += self::PAGE_SIZE) {
            $size = min(self::PAGE_SIZE, $maxRows - $start);
            $body = json_encode(['startDate' => $date, 'endDate' => $date, 'dimensions' => ['query', 'page'],
                'type' => 'web', 'dataState' => 'final', 'rowLimit' => $size, 'startRow' => $start], JSON_THROW_ON_ERROR);
            $data = $this->send($connection, 'POST', self::BASE . '/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query', $body)->json() ?? [];
            $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
            foreach ($rows as $row) {
                $parsed = self::row($row);
                if ($parsed !== null && is_array($row) && is_array($row['keys'] ?? null) && is_string($row['keys'][1] ?? null)) {
                    $result[] = new QueryPageRow($parsed[0], $row['keys'][1], $parsed[1]);
                }
            }
            if (count($rows) < $size) {
                break;
            }
        }
        return $result;
    }

    private function get(Connection $connection, string $url): HttpResponse
    {
        return $this->send($connection, 'GET', $url, null);
    }

    private function send(Connection $connection, string $method, string $url, ?string $body): HttpResponse
    {
        $token = $this->tokens->accessTokenFor($connection);
        $headers = ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        try {
            $response = $this->http->send($method, $url, $headers, $body, self::HOSTS);
        } catch (HttpClientException $e) {
            throw new IntegrationException('Could not reach Google Search Console: ' . $e->getMessage(), $e->retryable);
        }

        return GoogleErrors::check($response);
    }
}

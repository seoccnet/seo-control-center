<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Adapters;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordMetrics;
use SeoControlCenter\Domain\Keywords\KeywordMetricsProvider;
use SeoControlCenter\Domain\Keywords\Markets;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * Keyword metrics from DataForSEO's Google Ads search volume endpoint
 * (live mode): up to 1,000 keywords per request, one request per locale.
 */
final readonly class DataForSeoKeywordMetrics implements KeywordMetricsProvider
{
    public const string PATH = '/v3/keywords_data/google_ads/search_volume/live';
    public const string OPERATION = 'keyword_metrics';
    /** DataForSEO's list price per live search volume request; the settled cost is what DataForSEO reports. */
    public const float ESTIMATED_COST = 0.075;
    private const int MAX_KEYWORDS = 1000;

    public function __construct(
        private DataForSeoClient $client,
        private DataForSeoProfileRepository $profiles,
    ) {}

    public function isAvailable(int $workspaceId): bool
    {
        return $this->profiles->find($workspaceId)?->isActive() ?? false;
    }

    public function maxKeywordsPerRequest(): int
    {
        return self::MAX_KEYWORDS;
    }

    public function fetch(int $workspaceId, ?int $projectId, array $keywords, KeywordLocale $locale): array
    {
        if ($keywords === []) {
            return [];
        }
        if (count($keywords) > self::MAX_KEYWORDS) {
            throw new \InvalidArgumentException('Too many keywords for one request.');
        }

        // Google Ads filters volumes by its own short list of languages; any other language
        // (Bosnian, for example) is refused, so the country alone is asked for (0.44.3).
        $language = Markets::adsLanguage($locale->languageCode);
        $tasks = $this->client->post($workspaceId, $projectId, self::OPERATION, self::PATH, [[
            'keywords' => array_values($keywords),
            'location_code' => $locale->locationCode,
            ...($language !== null ? ['language_code' => $language] : []),
        ]], self::ESTIMATED_COST);

        $task = $tasks[0] ?? throw new IntegrationException('DataForSEO returned no task.', true);
        $error = DataForSeoErrors::taskError($task);
        if ($error !== null) {
            throw $error;
        }
        if (($task['status_code'] ?? null) !== DataForSeoErrors::OK) {
            throw new IntegrationException('DataForSEO returned incomplete keyword metrics. Previous metrics were retained.', false);
        }

        if (!array_key_exists('result', $task) || ($task['result'] !== null && (!is_array($task['result']) || !array_is_list($task['result'])))) {
            throw new IntegrationException('DataForSEO returned invalid keyword metrics. Previous metrics were retained.', false);
        }
        $metrics = [];
        $requested = array_fill_keys(array_map(Keyword::normalize(...), $keywords), true);
        $decoded = [];
        foreach ($keywords as $input) {
            $original = Keyword::normalize($input);
            // The provider decodes '+' and percent escapes in returned keyword text.
            $decoded[Keyword::normalize(urldecode($original))][$original] = $original;
        }
        foreach (is_array($task['result'] ?? null) ? $task['result'] : [] as $item) {
            if (!is_array($item) || !is_string($item['keyword'] ?? null)) {
                throw new IntegrationException('DataForSEO returned an invalid keyword metrics row.', false);
            }
            $keyword = Keyword::normalize($item['keyword']);
            $matches = array_values($decoded[$keyword] ?? []);
            if (count($matches) > 1) {
                throw new IntegrationException('DataForSEO returned an ambiguous keyword after decoding. Previous metrics were retained.', false);
            }
            if (!isset($requested[$keyword]) && count($matches) === 1) {
                $keyword = $matches[0];
            }
            if (!isset($requested[$keyword])) {
                throw new IntegrationException('DataForSEO returned metrics for an unrequested keyword. Previous metrics were retained.', false);
            }
            $volume = self::int($item['search_volume'] ?? null);
            $cpc = self::float($item['cpc'] ?? null);
            $competition = self::int($item['competition_index'] ?? null);
            $level = $item['competition'] ?? null;
            if ($level !== null && (!is_string($level) || !in_array(strtolower($level), ['high', 'medium', 'low'], true))) {
                throw new IntegrationException('DataForSEO returned an invalid competition level. Previous metrics were retained.', false);
            }
            // Match database limits and the provider's 0–100 competition range.
            if (($volume !== null && $volume > 4294967295)
                || ($cpc !== null && round($cpc, 2) >= 100000000)
                || ($competition !== null && $competition > 100)) {
                throw new IntegrationException('DataForSEO returned keyword metrics outside storage limits. Previous metrics were retained.', false);
            }
            $metrics[$keyword] = new KeywordMetrics(
                $keyword,
                $volume,
                $cpc,
                $competition,
                $level !== null ? strtolower($level) : null,
                self::monthly($item['monthly_searches'] ?? null),
            );
        }

        return array_values($metrics);
    }

    /**
     * @return list<array{year: int, month: int, volume: int|null}>
     */
    private static function monthly(mixed $rows): array
    {
        $monthly = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_int($row['year'] ?? null) && $row['year'] >= 1900 && $row['year'] <= 9999 && is_int($row['month'] ?? null) && $row['month'] >= 1 && $row['month'] <= 12) {
                $monthly[$row['year'] . '-' . $row['month']] = ['year' => $row['year'], 'month' => $row['month'], 'volume' => self::int($row['search_volume'] ?? null)];
            }
        }
        usort($monthly, static fn(array $a, array $b): int => [$b['year'], $b['month']] <=> [$a['year'], $a['month']]);

        return array_slice($monthly, 0, 24);
    }

    private static function int(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || (is_float($value) && $value >= PHP_INT_MAX)) {
            throw new IntegrationException('DataForSEO returned an invalid numeric keyword metric. Previous metrics were retained.', false);
        }
        return is_int($value) ? $value : (int) round($value);
    }

    private static function float(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
            throw new IntegrationException('DataForSEO returned an invalid numeric keyword metric. Previous metrics were retained.', false);
        }
        return (float) $value;
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

/** Curated Google evidence. Provider text is data, never instructions. */
final readonly class Inspection
{
    public const string NOTICE = 'Google’s stored index version, not a live test or indexing request. Results may lag changes on your site. Unchecked and failed checks do not mean a page is absent from Google.';
    private const int MAX_URLS = 5;
    private const int MAX_RICH_ITEMS = 10;
    private const int MAX_ISSUES = 5;

    /**
     * @param list<string> $referringUrls pages Google found linking here (a sample)
     * @param list<string> $sitemaps sitemaps that list the URL
     * @param list<array{type: string, issues: list<array{message: string, severity: string}>}> $richItems
     */
    public function __construct(
        public string $verdict,
        public string $coverage,
        public string $robots,
        public string $indexing,
        public string $fetch,
        public ?string $lastCrawl,
        public string $googleCanonical,
        public string $userCanonical,
        public string $crawledAs,
        public array $referringUrls = [],
        public array $sitemaps = [],
        public string $richVerdict = '',
        public array $richItems = [],
    ) {}

    /**
     * Reads Google's indexStatusResult (with the inspection's richResultsResult
     * under that key) or a stored present() array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $clean = static fn(mixed $value, int $limit = 300): string => is_string($value) ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $value) ?? '', 0, $limit) : '';
        $text = static fn(string $key, int $limit = 300): string => $clean($data[$key] ?? null, $limit);
        $urls = static function (mixed $list) use ($clean): array {
            $urls = [];
            foreach (is_array($list) ? $list : [] as $url) {
                $url = $clean($url, 2048);
                if (preg_match('#^https?://#i', $url) === 1) {
                    $urls[] = $url;
                }
            }

            return array_slice(array_values(array_unique($urls)), 0, self::MAX_URLS);
        };
        $verdict = $text('verdict');
        $crawl = $text('lastCrawlTime');
        $date = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $crawl)) {
            try {
                $date = (new \DateTimeImmutable($crawl))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                $date = null;
            }
        }

        // Stored results keep the curated shape; Google's response nests detected items and their issues.
        $richVerdict = '';
        $richItems = [];
        if (is_array($data['richResults'] ?? null)) {
            $richVerdict = $clean($data['richResults']['verdict'] ?? null, 20);
            foreach (is_array($data['richResults']['items'] ?? null) ? $data['richResults']['items'] : [] as $item) {
                if (is_array($item)) {
                    $richItems[] = ['type' => $clean($item['type'] ?? null, 100), 'issues' => self::issues($item['issues'] ?? null, $clean)];
                }
            }
        } elseif (is_array($data['richResultsResult'] ?? null)) {
            $richVerdict = $clean($data['richResultsResult']['verdict'] ?? null, 20);
            foreach (is_array($data['richResultsResult']['detectedItems'] ?? null) ? $data['richResultsResult']['detectedItems'] : [] as $detected) {
                if (!is_array($detected)) {
                    continue;
                }
                $issues = [];
                foreach (is_array($detected['items'] ?? null) ? $detected['items'] : [] as $item) {
                    $issues = [...$issues, ...(is_array($item) ? self::issues(array_map(static fn(mixed $i): mixed => is_array($i) ? ['message' => $i['issueMessage'] ?? null, 'severity' => $i['severity'] ?? null] : null, is_array($item['issues'] ?? null) ? $item['issues'] : []), $clean) : [])];
                }
                $richItems[] = ['type' => $clean($detected['richResultType'] ?? null, 100), 'issues' => array_slice(array_values(array_unique($issues, SORT_REGULAR)), 0, self::MAX_ISSUES)];
            }
        }
        $richItems = array_slice(array_values(array_filter($richItems, static fn(array $i): bool => $i['type'] !== '')), 0, self::MAX_RICH_ITEMS);

        return new self(
            in_array($verdict, ['PASS', 'PARTIAL', 'FAIL', 'NEUTRAL'], true) ? $verdict : 'UNKNOWN',
            $text('coverageState'),
            $text('robotsTxtState'),
            $text('indexingState'),
            $text('pageFetchState'),
            $date,
            $text('googleCanonical', 2048),
            $text('userCanonical', 2048),
            $text('crawledAs'),
            $urls($data['referringUrls'] ?? null),
            $urls($data['sitemap'] ?? null),
            in_array($richVerdict, ['PASS', 'PARTIAL', 'FAIL', 'NEUTRAL'], true) ? $richVerdict : '',
            $richItems,
        );
    }

    /**
     * Google chose a different canonical than the page declares. Both must be
     * known: a page without a declared canonical is not a mismatch.
     */
    public function canonicalMismatch(): bool
    {
        return $this->googleCanonical !== '' && $this->userCanonical !== '' && rtrim($this->googleCanonical, '/') !== rtrim($this->userCanonical, '/');
    }

    /** Detected rich results have errors (Google's ERROR severity). */
    public function richResultErrors(): bool
    {
        foreach ($this->richItems as $item) {
            foreach ($item['issues'] as $issue) {
                if ($issue['severity'] === 'ERROR') {
                    return true;
                }
            }
        }

        return $this->richVerdict === 'FAIL';
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'verdict' => $this->verdict,
            'coverageState' => $this->coverage,
            'robotsTxtState' => $this->robots,
            'indexingState' => $this->indexing,
            'pageFetchState' => $this->fetch,
            'lastCrawlTime' => $this->lastCrawl === null ? null : str_replace(' ', 'T', $this->lastCrawl) . 'Z',
            'googleCanonical' => $this->googleCanonical,
            'userCanonical' => $this->userCanonical,
            'crawledAs' => $this->crawledAs,
            'referringUrls' => $this->referringUrls,
            'sitemap' => $this->sitemaps,
            'richResults' => ['verdict' => $this->richVerdict, 'items' => $this->richItems],
        ];
    }

    /**
     * @param \Closure(mixed, int=): string $clean
     * @return list<array{message: string, severity: string}>
     */
    private static function issues(mixed $issues, \Closure $clean): array
    {
        $result = [];
        foreach (is_array($issues) ? $issues : [] as $issue) {
            if (!is_array($issue)) {
                continue;
            }
            $message = $clean($issue['message'] ?? null, 300);
            $severity = $clean($issue['severity'] ?? null, 20);
            if ($message !== '') {
                $result[] = ['message' => $message, 'severity' => in_array($severity, ['ERROR', 'WARNING'], true) ? $severity : 'WARNING'];
            }
        }

        return array_slice($result, 0, self::MAX_ISSUES);
    }
}

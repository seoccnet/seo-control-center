<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

final readonly class OpportunityFilter
{
    public const array BASES = ['search_console', 'bing', 'ga4', 'rankings', 'research', 'site_audit', 'web_vitals', 'indexing', 'internal_links', 'backlinks', 'local', 'ai'];
    public const array VERDICTS = ['target', 'consider', 'reconsider'];
    public const array ZERO_CLICK = ['high', 'medium', 'low'];
    public function __construct(public string $pageUrl = '', public int $minImpact = 0, public ?int $effort = null, public string $basis = '', public string $verdict = '', public string $zeroClick = '') {}
    /** @param array<string, mixed> $input */
    public static function fromArray(array $input): self
    {
        $page = $input['page_url'] ?? '';
        $basis = $input['data_basis'] ?? '';
        $impact = $input['min_impact'] ?? 0;
        $effort = $input['effort'] ?? '';
        if (!is_string($page) || strlen($page) > 2048 || !is_string($basis) || !in_array($basis, ['', ...self::BASES], true)) {
            throw new \InvalidArgumentException('Invalid page or data-basis filter.');
        }
        if (is_string($impact) && ctype_digit($impact)) {
            $impact = (int) $impact;
        }
        if (!is_int($impact) || $impact < 0 || $impact > 100) {
            throw new \InvalidArgumentException('Minimum impact must be between 0 and 100.');
        }
        if (is_string($effort) && ctype_digit($effort)) {
            $effort = (int) $effort;
        }
        if (!in_array($effort, ['', null, 1, 2, 3], true)) {
            throw new \InvalidArgumentException('Effort must be 1, 2 or 3.');
        }
        $verdict = $input['verdict'] ?? '';
        $zeroClick = $input['zero_click'] ?? '';
        if (!is_string($verdict) || !in_array($verdict, ['', ...self::VERDICTS], true) || !is_string($zeroClick) || !in_array($zeroClick, ['', ...self::ZERO_CLICK], true)) {
            throw new \InvalidArgumentException('Invalid verdict or zero-click filter.');
        }
        return new self($page, $impact, is_int($effort) ? $effort : null, $basis, $verdict, $zeroClick);
    }
    public function matches(StoredOpportunity $row): bool
    {
        if ($row->impact < $this->minImpact || $this->effort !== null && $row->effort !== $this->effort) {
            return false;
        }
        if ($this->verdict !== '' && $row->verdict() !== $this->verdict || $this->zeroClick !== '' && $row->zeroClickRisk() !== $this->zeroClick) {
            return false;
        }
        if ($this->pageUrl !== '') {
            $found = false;
            $wantedPath = rtrim((string) (parse_url($this->pageUrl, PHP_URL_PATH) ?: '/'), '/') ?: '/';
            $wantedHost = parse_url($this->pageUrl, PHP_URL_HOST);
            foreach ($row->evidence as $item) {
                if (!array_any(['Page', 'Ranking URL', 'URL', 'Value page'], static fn(string $label): bool => $item['label'] === $label || str_ends_with($item['label'], ' supporting: ' . $label))) {
                    continue;
                }
                $url = explode(' (', $item['value'], 2)[0];
                $path = rtrim((string) (parse_url($url, PHP_URL_PATH) ?: '/'), '/') ?: '/';
                $host = parse_url($url, PHP_URL_HOST);
                if ($path === $wantedPath && (!is_string($wantedHost) || is_string($host) && mb_strtolower($host) === mb_strtolower($wantedHost))) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }
        if ($this->basis !== '') {
            foreach ($row->evidence as $item) {
                if (!str_ends_with($item['label'], 'Required sources')) {
                    continue;
                }
                foreach (explode(', ', $item['value']) as $source) {
                    if ($source === $this->basis || match ($this->basis) {
                        'research' => str_starts_with($source, 'research_'),
                        'backlinks' => str_starts_with($source, 'backlink_'),
                        'local' => str_starts_with($source, 'local_'),
                        'ai' => str_starts_with($source, 'ai_'),
                        'indexing' => str_starts_with($source, 'inspection:') || in_array($source, ['sitemaps', 'bing_crawl'], true),
                        'search_console' => $source === 'search_console_surfaces',
                        'bing' => $source === 'bing_crawl',
                        default => false,
                    }) {
                        return true;
                    }
                }
            }
            return false;
        }
        return true;
    }
}

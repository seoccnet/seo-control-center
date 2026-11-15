<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\International\CountryCodes;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\SiteAudit\Crawler\Hreflang;

/**
 * Countries next to the hreflang setup (1.2.1): the language versions a
 * completed audit found, and the countries that bring Search Console
 * clicks, each with the versions that target it by region. Only regional
 * codes (en-GB) can be matched to a country; a site that targets languages
 * only (en, de) is reported as such, without guessing which language a
 * country speaks.
 */
final readonly class InternationalTargeting
{
    public const int DAYS = 28;
    public const int COUNTRIES = 15;

    public function __construct(
        private SiteAuditRepository $audits,
        private SearchPerformanceReader $search,
        private Clock $clock,
    ) {}

    /**
     * @return array{
     *     codes: list<array{code: string, pages: int}>,
     *     regional: bool,
     *     hasDefault: bool,
     *     range: DateRange,
     *     countries: list<array{country: string, region: ?string, clicks: int, impressions: int, versions: list<string>}>
     * }|null null when the audit found no valid hreflang
     */
    public function report(Project $project, SiteAudit $audit): ?array
    {
        // Each page counts once for its own code; codes only named for other pages still appear, with 0 pages.
        $codes = [];
        foreach ($this->audits->allPages($audit->id) as $page) {
            foreach ($page->hreflang ?? [] as $entry) {
                if (Hreflang::problem($entry['lang']) !== null) {
                    continue;
                }
                $codes[$entry['lang']] = ($codes[$entry['lang']] ?? 0) + ($entry['url'] === $page->url ? 1 : 0);
            }
        }
        if ($codes === []) {
            return null;
        }
        arsort($codes);
        $regions = [];
        foreach (array_keys($codes) as $code) {
            $region = self::region((string) $code);
            if ($region !== null) {
                $regions[$region][] = (string) $code;
            }
        }
        $today = new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));
        $range = new DateRange($today->modify('-' . self::DAYS . ' days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d'));
        $countries = [];
        foreach ($this->search->top($project->id, PerformanceDimension::Country, $range, self::COUNTRIES) as $row) {
            $region = CountryCodes::alpha2($row->key);
            $countries[] = ['country' => strtoupper($row->key), 'region' => $region !== null ? strtoupper($region) : null, 'clicks' => $row->metrics->clicks, 'impressions' => $row->metrics->impressions, 'versions' => $region !== null ? ($regions[$region] ?? []) : []];
        }

        return [
            'codes' => array_map(static fn(string|int $code, int $pages): array => ['code' => (string) $code, 'pages' => $pages], array_keys($codes), array_values($codes)),
            'regional' => $regions !== [],
            'hasDefault' => isset($codes[Hreflang::DEFAULT]),
            'range' => $range,
            'countries' => $countries,
        ];
    }

    /** The region of a code, lower case: "gb" for en-gb; null for language-only codes, numeric regions and x-default. */
    private static function region(string $code): ?string
    {
        return preg_match('/^[a-z]{2,3}(?:-[a-z]{4})?-([a-z]{2})$/', strtolower($code), $m) === 1 ? $m[1] : null;
    }
}

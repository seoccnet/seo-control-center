<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\StructuredData\SchemaGenerator;

/**
 * The structured data generator (1.5.0): suggests a schema.org type for a
 * page of the latest completed audit, fills the form from what the crawl
 * read (title, headline, description, business details), and builds the
 * JSON-LD from the submitted fields. Nothing is stored or fetched.
 */
final readonly class StructuredDataService
{
    public const int PAGE_CHOICES = 500;

    public function __construct(private SiteAuditRepository $audits) {}

    /**
     * @param array<string, mixed> $submitted field => value, as sent by the form
     * @return array{
     *     type: string, url: string, pages: list<string>, page: ?AuditPage, values: array<string, string>,
     *     result: ?array{json: string, missing: list<string>, notes: list<string>}, existing: list<string>, audited: bool
     * }
     */
    public function form(Project $project, string $url, string $type, array $submitted, bool $generate): array
    {
        $audit = $this->audits->latest($project->id, 'completed');
        $pages = [];
        if ($audit !== null) {
            foreach ($this->audits->allPages($audit->id) as $candidate) {
                if ($candidate->state === 'done' && $candidate->isHtml() && ($candidate->statusCode ?? 0) < 300 && !$candidate->noindex) {
                    $pages[] = $candidate->url;
                }
                if (count($pages) >= self::PAGE_CHOICES) {
                    break;
                }
            }
        }
        $site = 'https://' . strtolower($project->primaryDomain) . '/';
        $url = self::ownUrl($url, $project->primaryDomain) ? $url : ($pages[0] ?? $site);
        $page = $audit !== null ? $this->audits->findPage($audit->id, $url) : null;
        if (!isset(SchemaGenerator::TYPES[$type])) {
            $type = self::suggest($url, $page);
        }
        $values = self::prefill($type, $url, $page, $project);
        if ($generate) {
            foreach (array_keys(SchemaGenerator::TYPES[$type]['fields']) as $field) {
                $values[$field] = is_string($submitted[$field] ?? null) ? mb_substr($submitted[$field], 0, 20000) : '';
            }
        }

        return [
            'type' => $type,
            'url' => $url,
            'pages' => $pages,
            'page' => $page,
            'values' => $values,
            'result' => $generate ? SchemaGenerator::generate($type, $values, $url) : null,
            'existing' => $page->schemaTypes ?? [],
            'audited' => $audit !== null,
        ];
    }

    /** The type that fits the page best. */
    public static function suggest(string $url, ?AuditPage $page): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if ($path === '' || $path === '/') {
            return 'Organization';
        }
        if ($page?->isProduct() ?? false) {
            return 'Product';
        }
        if ($page?->isArticle() ?? false) {
            return 'Article';
        }
        if (($page?->business['address'] ?? null) !== null) {
            return 'LocalBusiness';
        }

        return 'BreadcrumbList';
    }

    /** @return array<string, string> */
    private static function prefill(string $type, string $url, ?AuditPage $page, Project $project): array
    {
        $origin = (string) parse_url($url, PHP_URL_SCHEME) . '://' . (string) parse_url($url, PHP_URL_HOST) . '/';
        $title = trim((string) ($page->title ?? ''));
        $headline = trim((string) ($page->h1 ?? '')) !== '' ? trim((string) $page?->h1) : $title;
        $business = $page?->business;
        $name = is_string($business['name'] ?? null) ? $business['name'] : $project->name;
        $phone = $business['phones'][0] ?? '';

        return match ($type) {
            'Organization' => ['name' => $name, 'url' => $origin, 'description' => (string) ($page->description ?? ''), 'telephone' => $phone],
            'LocalBusiness' => ['name' => $name, 'url' => $origin, 'telephone' => $phone, 'streetAddress' => (string) ($business['address'] ?? '')],
            'WebSite' => ['name' => $project->name, 'url' => $origin],
            'Article' => ['headline' => $headline, 'description' => (string) ($page->description ?? ''), 'publisher' => $project->name],
            'Product' => ['name' => $headline, 'description' => (string) ($page->description ?? '')],
            'BreadcrumbList' => ['trail' => SchemaGenerator::trailFor($url)],
            default => [],
        };
    }

    private static function ownUrl(string $url, string $domain): bool
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return false;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $domain = strtolower($domain);
        $bare = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;

        return $host === $bare || str_ends_with($host, '.' . $bare);
    }
}

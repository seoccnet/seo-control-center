<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer;

/** One mobile browser page, submitted once; subsequent result reads are free. */
final readonly class RenderedPageProvider
{
    public const float ESTIMATED_COST = 0.0051;
    public const string SUBMIT_PATH = '/v3/on_page/task_post';

    /** Bounded DOM/resource sample, fixed by SEOcc; no workspace-supplied script. */
    public const string SNAPSHOT_SCRIPT = <<<'JS'
        (() => {
          const text = (v, n) => String(v || '').slice(0, n);
          const nodes = Array.from(document.querySelectorAll('*')).slice(0, 100);
          const resources = [];
          const add = (url, kind) => { if (url && resources.length < 100) resources.push({url: text(url, 2048), kind}); };
          performance.getEntriesByType('resource').slice(0, 60).forEach(r => add(r.name, 'loaded'));
          [null, '::before', '::after'].forEach(p => nodes.forEach(el => {
            if (resources.length >= 100) return;
            const css = getComputedStyle(el, p).backgroundImage.slice(0, 16384);
            for (const m of css.matchAll(/url\((?:"([^"]*)"|'([^']*)'|([^)]*))\)/g)) {
              add(m[1] || m[2] || m[3], p ? 'css_' + p.slice(2) + '_background' : 'css_background');
              if (resources.length >= 100) break;
            }
          }));
          const images = Array.from(document.querySelectorAll('img')).slice(0, 40), lazy = [];
          images.forEach(el => { add(el.currentSrc || el.src, 'image');
            ['data-src','data-original','data-lazy-src'].forEach(a => { const u = el.getAttribute(a); if (u && lazy.length < 40) lazy.push(text(u, 2048)); }); });
          return {url: location.href, base_url: document.baseURI?.length <= 2048 ? document.baseURI : null, title: text(document.title, 500),
            description: text(document.querySelector('meta[name="description"]')?.content, 1000),
            h1s: Array.from(document.querySelectorAll('h1')).slice(0, 20).map(el => text(el.textContent, 500)),
            links: Array.from(document.querySelectorAll('a[href]')).slice(0, 100).map(el => text(el.href, 2048)),
            missing_alt: Array.from(document.querySelectorAll('img')).slice(0, 100).filter(el => !el.hasAttribute('alt')).length,
            resources, lazy_images: lazy,
            srcsets: Array.from(document.querySelectorAll('img,picture source')).slice(0,20).flatMap(el => ['srcset','data-srcset'].map(a => el.getAttribute(a))).filter(s => s && s.length <= 2048).slice(0,20)};
        })()
        JS;

    public function __construct(private DataForSeoClient $client) {}

    public function submit(int $workspaceId, int $projectId, string $url, int $maxPages = 1, bool $dismissCookiePopup = false): string
    {
        if (!in_array($maxPages, [1, 10, 50, 100], true)) {
            throw new \InvalidArgumentException('Invalid rendered crawl page limit.');
        }
        $tasks = $this->client->post($workspaceId, $projectId, 'site_audit.rendered', self::SUBMIT_PATH, [[
            'target' => preg_replace('/^www\./', '', UrlNormalizer::host($url)), 'start_url' => $url, 'max_crawl_pages' => $maxPages,
            'force_sitewide_checks' => false, 'allow_subdomains' => false,
            'load_resources' => true, 'enable_javascript' => true, 'enable_browser_rendering' => true, 'enable_xhr' => true,
            'browser_preset' => 'mobile', 'custom_user_agent' => 'SEO-Control-Center', 'accept_language' => 'en',
            'custom_js' => self::SNAPSHOT_SCRIPT,
            'disable_cookie_popup' => $dismissCookiePopup, 'support_cookies' => true,
        ]], self::ESTIMATED_COST * $maxPages);
        $task = $tasks[0] ?? [];
        if (($error = DataForSeoErrors::taskError($task)) !== null) {
            throw $error;
        }
        $id = $task['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id) !== 1) {
            throw new IntegrationException('DataForSEO returned no valid rendered audit task. Review usage before resubmitting.', false, chargeUncertain: true);
        }
        return $id;
    }

    /** @return array<string, mixed>|null null while the submitted crawl is still running */
    public function result(int $workspaceId, string $taskId, string $url): ?array
    {
        $task = $this->client->onPageTask($workspaceId, $taskId);
        if (in_array($task['status_code'] ?? null, [20100, 40601, 40602], true)) {
            return null;
        }
        if (($error = DataForSeoErrors::taskError($task)) !== null) {
            throw new IntegrationException($error->getMessage(), $error->retryable, $error->authorizationLost, $error->retryAfterSeconds, providerStatusCode: $error->providerStatusCode);
        }
        $result = $task['result'][0] ?? null;
        if (!is_array($result) || !in_array($result['crawl_progress'] ?? null, ['in_progress', 'finished'], true)) {
            throw new IntegrationException('DataForSEO returned invalid rendered audit progress.', true);
        }
        if ($result['crawl_progress'] === 'in_progress') {
            return null;
        }
        $item = $result['items'][0] ?? null;
        if (!is_array($item)) {
            throw new IntegrationException('Missing rendered page.', false);
        }
        return $this->page($item, $taskId, $url);
    }

    /** @return array{rows: list<array<string, mixed>>, total: int}|null */
    public function pages(int $workspaceId, string $taskId, string $startUrl, int $maxPages, int $offset): ?array
    {
        $task = $this->client->onPageTask($workspaceId, $taskId, min(20, $maxPages - $offset), $offset);
        $result = $this->ready($task);
        if ($result === null) {
            return null;
        }
        $total = $result['total_items_count'] ?? null;
        if (!is_int($total) || $total < 1 || $total > $maxPages || !is_array($result['items'] ?? null) || !array_is_list($result['items'])) {
            throw new IntegrationException('Invalid rendered crawl page count.', false);
        }
        $rows = [];
        foreach ($result['items'] as $item) {
            $url = is_array($item) && is_string($item['url'] ?? null) ? UrlNormalizer::normalize($item['url']) : null;
            if ($url === null || !str_starts_with($url, 'https://') || parse_url($url, PHP_URL_PORT) !== null
                || !in_array(UrlNormalizer::host($url), SiteAuditService::hosts(UrlNormalizer::host($startUrl)), true)) {
                throw new IntegrationException('Rendered crawl returned a page outside the project.', false);
            }
            if (($item['status_code'] ?? null) !== 200) {
                $rows[] = ['url' => $url, 'status' => is_int($item['status_code'] ?? null) ? $item['status_code'] : null, 'findings' => ['Page could not be rendered'], 'task_id' => $taskId];
            } else {
                $rows[] = $this->page($item, $taskId, $url);
            }
        }
        if ($rows === [] || count($rows) > min(20, $maxPages - $offset) || $offset + count($rows) > $total) {
            throw new IntegrationException('Invalid rendered crawl pagination.', false);
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /** @param array<array-key, mixed> $task
     * @return array<string, mixed>|null
     */
    private function ready(array $task): ?array
    {
        if (in_array($task['status_code'] ?? null, [20100, 40601, 40602], true)) {
            return null;
        }
        if (($error = DataForSeoErrors::taskError($task)) !== null) {
            throw new IntegrationException($error->getMessage(), $error->retryable, $error->authorizationLost, $error->retryAfterSeconds, providerStatusCode: $error->providerStatusCode);
        }
        $result = $task['result'][0] ?? null;
        if (!is_array($result) || !in_array($result['crawl_progress'] ?? null, ['in_progress', 'finished'], true)) {
            throw new IntegrationException('Invalid rendered crawl progress.', true);
        }
        return $result['crawl_progress'] === 'finished' ? $result : null;
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function page(array $item, string $taskId, string $url): array
    {
        $snapshot = $item['custom_js_response'] ?? null;
        if (!is_array($snapshot) || ($item['status_code'] ?? null) !== 200
            || ($item['custom_js_client_exception'] ?? null) !== null
            || !is_string($item['url'] ?? null) || UrlNormalizer::normalize($item['url']) !== $url
            || !is_string($snapshot['url'] ?? null) || UrlNormalizer::normalize($snapshot['url']) !== $url) {
            throw new IntegrationException('The browser did not return a complete snapshot of the selected page. Previous results were retained.', false);
        }
        foreach (['h1s', 'links', 'resources'] as $field) {
            if (!is_array($snapshot[$field] ?? null) || !array_is_list($snapshot[$field])) {
                throw new IntegrationException('DataForSEO returned invalid rendered page evidence.', false);
            }
        }
        // Missing on older provider snapshots; invalid supplied bases must not invent relative URLs.
        $referenceBase = array_key_exists('base_url', $snapshot)
            ? (is_string($snapshot['base_url']) ? UrlNormalizer::normalize($snapshot['base_url']) : null)
            : $url;
        $resources = [];
        foreach (array_slice($snapshot['resources'], 0, 100) as $resource) {
            if (!is_array($resource) || !is_string($resource['url'] ?? null) || !in_array($resource['kind'] ?? null, ['loaded', 'css_background', 'css_before_background', 'css_after_background', 'image'], true)) {
                continue;
            }
            $target = UrlNormalizer::resolve($resource['url'], $referenceBase ?? '');
            if ($target !== null) {
                $resources[$resource['kind'] . ':' . $target] = ['url' => $target, 'kind' => $resource['kind']];
            }
        }
        $links = [];
        foreach (array_slice($snapshot['links'], 0, 100) as $link) {
            if (is_string($link) && ($target = UrlNormalizer::resolve($link, $referenceBase ?? '')) !== null) {
                $links[$target] = true;
            }
        }
        $timing = is_array($item['page_timing'] ?? null) ? $item['page_timing'] : [];
        $lazyImages = null;
        if (is_array($snapshot['lazy_images'] ?? null) && array_is_list($snapshot['lazy_images'])) {
            $lazyImages = [];
            foreach (array_slice($snapshot['lazy_images'], 0, 40) as $candidate) {
                if (is_string($candidate) && ($target = UrlNormalizer::resolve($candidate, $referenceBase ?? '')) !== null) {
                    $lazyImages[$target] = true;
                }
            }
            $lazyImages = array_keys($lazyImages);
        }
        $number = static fn(mixed $value): ?float => is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
        $responsiveImages = null;
        if (is_array($snapshot['srcsets'] ?? null) && array_is_list($snapshot['srcsets'])) {
            $responsiveImages = [];
            foreach (array_slice($snapshot['srcsets'], 0, 20) as $declaration) {
                if (!is_string($declaration)) {
                    continue;
                }
                foreach (Crawler\SrcsetCandidates::urls($declaration) as $candidate) {
                    if (($target = UrlNormalizer::resolve($candidate, $referenceBase ?? '')) !== null) {
                        $responsiveImages[$target] = true;
                    }
                    if (count($responsiveImages) >= 40) {
                        break 2;
                    }
                }
            }
            $responsiveImages = array_keys($responsiveImages);
        }
        $checks = is_array($item['checks'] ?? null) ? $item['checks'] : [];
        $findings = [];
        foreach (['no_title' => 'Missing title', 'no_description' => 'Missing description', 'no_h1_tag' => 'Missing H1',
            'is_broken' => 'Broken page', 'is_4xx_code' => 'Page returns 4xx', 'is_5xx_code' => 'Page returns 5xx',
            'is_noindex' => 'Noindex', 'has_render_blocking_resources' => 'Render-blocking resources'] as $key => $label) {
            if (($checks[$key] ?? null) === true) {
                $findings[] = $label;
            }
        }
        foreach (['broken_resources' => 'Provider found broken resources', 'broken_links' => 'Provider found broken links'] as $key => $label) {
            if (($item[$key] ?? null) === true) {
                $findings[] = $label;
            }
        }
        return ['url' => $url, 'reference_base_url' => $referenceBase, 'status' => $item['status_code'], 'title' => is_string($snapshot['title'] ?? null) ? mb_substr($snapshot['title'], 0, 500) : null,
            'description' => is_string($snapshot['description'] ?? null) ? mb_substr($snapshot['description'], 0, 1000) : null,
            'h1s' => array_map(static fn(string $text): string => mb_substr($text, 0, 500), array_slice(array_values(array_filter($snapshot['h1s'], is_string(...))), 0, 20)),
            'links' => array_keys($links), 'resources' => array_values($resources), 'lazy_images' => $lazyImages, 'responsive_images' => $responsiveImages,
            'missing_alt' => is_int($snapshot['missing_alt'] ?? null) ? max(0, min(100, $snapshot['missing_alt'])) : null,
            'lcp_ms' => $number($timing['largest_contentful_paint'] ?? null), 'cls' => $number($item['meta']['cumulative_layout_shift'] ?? null),
            'findings' => $findings, 'task_id' => $taskId];
    }

    /** Free saved resource status / waterfall evidence. One provider request per invocation.
     * @return array{items: list<array<string, mixed>>, total: int}|null
     */
    public function evidence(int $workspaceId, string $taskId, string $url, string $kind, int $offset = 0): ?array
    {
        $result = $this->ready($this->client->onPageTask($workspaceId, $taskId, 100, $offset, $kind, $url));
        if ($result === null) {
            return null;
        }
        if (!is_array($result['items'] ?? null) || !array_is_list($result['items'])) {
            throw new IntegrationException('Invalid saved browser resource evidence.', false);
        }
        $items = [];
        $total = $result['total_items_count'] ?? count($result['items']);
        if (!is_int($total) || $total < 0 || $total > 10000) {
            throw new IntegrationException('Browser resource evidence exceeds the 10,000 resource limit.', false);
        }
        foreach ($result['items'] as $item) {
            if (!is_array($item)) {
                throw new IntegrationException('Invalid browser resource row.', false);
            }
            if ($kind === 'waterfall') {
                if (!is_string($item['page_url'] ?? null) || UrlNormalizer::normalize($item['page_url']) !== $url) {
                    throw new IntegrationException('Browser waterfall belongs to a different page.', false);
                }
                foreach (array_slice(is_array($item['resources'] ?? null) ? $item['resources'] : [], 0, 1000) as $resource) {
                    if (is_array($resource)) {
                        $items[] = $this->resourceEvidence($resource);
                    }
                }
            } else {
                $items[] = $this->resourceEvidence($item);
            }
        }
        if ($kind === 'resources' && count($items) > 100) {
            throw new IntegrationException('Browser resource pagination exceeds requested limit.', false);
        }
        if ($kind === 'resources' && ($offset + count($items) > $total || ($offset < $total && $items === []))) {
            throw new IntegrationException('Browser resource pagination does not match its total.', false);
        }
        return ['items' => $items, 'total' => $total];
    }

    /** @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function resourceEvidence(array $item): array
    {
        $url = is_string($item['url'] ?? null) ? UrlNormalizer::normalize($item['url']) : null;
        if ($url === null) {
            throw new IntegrationException('Invalid browser resource URL.', false);
        }
        $result = ['url' => $url, 'kind' => is_string($item['resource_type'] ?? null) ? mb_substr($item['resource_type'], 0, 50) : 'unknown', 'status' => is_int($item['status_code'] ?? null) ? $item['status_code'] : null];
        $timing = is_array($item['fetch_timing'] ?? null) ? $item['fetch_timing'] : $item;
        foreach (['fetch_start', 'fetch_end', 'duration_time', 'waiting_time', 'download_time'] as $field) {
            $value = $timing[$field] ?? null;
            $result[$field] = is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
        }
        return $result;
    }

    public const float LIGHTHOUSE_COST = 0.005;

    public function submitLighthouse(int $workspaceId, int $projectId, string $url): string
    {
        $tasks = $this->client->post($workspaceId, $projectId, 'site_audit.lighthouse', '/v3/on_page/lighthouse/task_post', [['url' => $url, 'for_mobile' => true, 'categories' => ['performance', 'seo', 'accessibility', 'best_practices'], 'custom_user_agent' => 'SEO-Control-Center']], self::LIGHTHOUSE_COST);
        $task = $tasks[0] ?? [];
        if (($error = DataForSeoErrors::taskError($task)) !== null) {
            throw $error;
        }
        $id = $task['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id) !== 1) {
            throw new IntegrationException('Invalid saved Lighthouse task identifier. Review usage before resubmitting.', false, chargeUncertain: true);
        }
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function lighthouse(int $workspaceId, string $taskId, string $url): ?array
    {
        $task = $this->client->onPageTask($workspaceId, $taskId, endpoint: 'lighthouse');
        if (in_array($task['status_code'] ?? null, [20100, 40601, 40602], true)) {
            return null;
        }
        if (($error = DataForSeoErrors::taskError($task)) !== null) {
            throw new IntegrationException($error->getMessage(), $error->retryable, $error->authorizationLost);
        }
        $data = $task['result'][0] ?? null;
        if (!is_array($data) || !is_string($data['requestedUrl'] ?? null) || UrlNormalizer::normalize($data['requestedUrl']) !== $url
            || !is_string($data['finalUrl'] ?? null) || UrlNormalizer::normalize($data['finalUrl']) !== $url
            || isset($data['runtimeError']) || ($data['configSettings']['formFactor'] ?? null) !== 'mobile'
            || !is_array($data['audits'] ?? null) || !is_array($data['categories'] ?? null)) {
            throw new IntegrationException('Lighthouse returned incomplete or mismatched page/device data.', false);
        }
        $categories = [];
        foreach (['performance', 'seo', 'accessibility', 'best-practices'] as $key) {
            $score = $data['categories'][$key]['score'] ?? null;
            $categories[$key] = is_numeric($score) && is_finite((float) $score) && (float) $score >= 0 && (float) $score <= 1 ? (float) $score : null;
        }
        $metrics = [];
        foreach (['largest-contentful-paint', 'cumulative-layout-shift', 'total-blocking-time', 'first-contentful-paint', 'speed-index'] as $key) {
            $value = $data['audits'][$key]['numericValue'] ?? null;
            $metrics[$key] = is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
        }
        if ($categories['performance'] === null || $metrics['largest-contentful-paint'] === null || $metrics['cumulative-layout-shift'] === null) {
            throw new IntegrationException('Lighthouse did not return usable performance diagnostics.', false);
        }
        $findings = [];
        foreach ($data['audits'] as $key => $audit) {
            if (is_array($audit) && is_string($key) && is_string($audit['title'] ?? null) && is_numeric($audit['score'] ?? null) && (float) $audit['score'] < 1 && ($audit['scoreDisplayMode'] ?? null) !== 'informative') {
                $findings[] = ['id' => mb_substr($key, 0, 100), 'title' => mb_substr($audit['title'], 0, 300), 'display' => is_string($audit['displayValue'] ?? null) ? mb_substr($audit['displayValue'], 0, 300) : null];
                if (count($findings) >= 50) {
                    break;
                }
            }
        }
        return ['url' => $url, 'mode' => 'lighthouse', 'task_id' => $taskId, 'categories' => $categories, 'metrics' => $metrics, 'lighthouse_findings' => $findings, 'version' => is_string($data['lighthouseVersion'] ?? null) ? $data['lighthouseVersion'] : null];
    }
}

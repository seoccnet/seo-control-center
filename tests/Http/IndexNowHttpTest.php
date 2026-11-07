<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Modules\IndexNow\Api\IndexNowClient;
use SeoControlCenter\Modules\IndexNow\IndexNowService;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIndexNow;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * IndexNow: key file, verification, manual and sitemap submissions,
 * permissions and tenant isolation (against a simulated site and IndexNow).
 */
final class IndexNowHttpTest extends TestCase
{
    private Scenario $s;

    private FakeHttpClient $http;

    private InMemoryIndexNow $indexNow;

    private string $sitemap = '';

    /** @var list<list<string>> URL lists sent to IndexNow */
    private array $sent = [];

    /** @var list<string> keyLocation of each submission */
    private array $locations = [];

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $http = $this->s->container->get(HttpClient::class);
        $indexNow = $this->s->container->get(InMemoryIndexNow::class);
        self::assertInstanceOf(FakeHttpClient::class, $http);
        self::assertInstanceOf(InMemoryIndexNow::class, $indexNow);
        $this->http = $http;
        $this->indexNow = $indexNow;
        $this->http->on('GET', 'https://shop.acme.test/sitemap.xml', fn(): HttpResponse => new HttpResponse(200, $this->sitemap));
        $this->http->on('POST', IndexNowClient::ENDPOINT, function (string $url, array $headers, ?string $body): HttpResponse {
            $data = json_decode((string) $body, true);
            self::assertIsArray($data);
            self::assertSame('shop.acme.test', $data['host']);
            $this->sent[] = $data['urlList'];
            $this->locations[] = $data['keyLocation'];

            return new HttpResponse(200, '');
        });
    }

    public function testKeyFileIsFoundOnTheSite(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $base = $this->url();
        self::assertStringContainsString('Set up IndexNow', (string) $owner->get($base)->getBody());

        self::assertSame(303, $owner->post($base . '/setup')->getStatusCode());
        $key = $this->indexNow->settings($this->s->acmeProject->id)->key ?? '';
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        $file = $owner->get($base . '/key-file');
        self::assertSame($key, (string) $file->getBody());
        self::assertSame('attachment; filename="' . $key . '.txt"', $file->getHeaderLine('Content-Disposition'));

        $this->http->on('GET', 'https://shop.acme.test/', static fn(): HttpResponse => new HttpResponse(404, 'Not found'));
        $owner->get($base);
        $missing = $owner->post($base . '/verify');
        self::assertSame(422, $missing->getStatusCode());
        self::assertStringContainsString('The key file was not found.', (string) $missing->getBody());

        $this->http->on('GET', 'https://shop.acme.test/' . $key . '.txt', static fn(): HttpResponse => new HttpResponse(200, $key . "\n"));
        $owner->get($base);
        self::assertSame(303, $owner->post($base . '/verify')->getStatusCode());
        self::assertSame('shop.acme.test', $this->indexNow->settings($this->s->acmeProject->id)?->host);
        self::assertStringContainsString('Key file found at https://shop.acme.test/' . $key . '.txt', (string) $owner->get($base)->getBody());
        self::assertContains('project.indexnow_key_created', $this->s->audit()->actions());
    }

    public function testCompanionPluginDownloadRequiresProjectIntegrationPermissions(): void
    {
        $base = $this->url();
        $owner = $this->s->loggedIn($this->s->owner);
        $response = $owner->get($base . '/wordpress-plugin');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('PK', (string) $response->getBody());
        self::assertStringContainsString('seocc-0.3.1.zip', $response->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('Download SEOcc WordPress plugin', (string) $owner->get($base)->getBody());
        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertSame(403, $viewer->get($base . '/wordpress-plugin')->getStatusCode());
        self::assertStringNotContainsString('Download SEOcc WordPress plugin', (string) $viewer->get($base)->getBody());
        self::assertSame(404, $owner->get('/projects/' . $this->s->rivalProject->publicId . '/indexnow/wordpress-plugin')->getStatusCode());
    }

    public function testOverviewPromotesOptionalPluginOnlyToIntegrationManagers(): void
    {
        $base = '/projects/' . $this->s->acmeProject->publicId;
        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($base)->getBody();
        self::assertStringContainsString('Use WordPress? Meet SEOcc', $html);
        self::assertStringContainsString('0.1.0:' . $this->s->owner->publicId . ':' . $this->s->acmeProject->publicId, $html);
        self::assertStringContainsString($base . '/indexnow/wordpress-plugin', $html);
        self::assertStringContainsString($base . '/indexnow#wordpress-companion', $html);
        self::assertStringContainsString('data-wordpress-dismiss hidden', $html);
        self::assertStringNotContainsString('data-wordpress-notice', (string) $this->s->loggedIn($this->s->viewer)->get($base)->getBody());
        $explanation = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('id="wordpress-companion"', $explanation);
        self::assertStringContainsString('Install and connect', $explanation);
        self::assertStringContainsString('does not guarantee indexing', $explanation);
        self::assertSame([], $this->sent);
    }

    public function testManualSubmissionsStayOnTheSite(): void
    {
        $this->verified();
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());

        $offHost = $owner->post($this->url() . '/submit', ['urls' => "https://shop.acme.test/a\nhttps://evil.test/b"]);
        self::assertSame(422, $offHost->getStatusCode());
        self::assertStringContainsString('Only addresses on https://shop.acme.test/ can be submitted', (string) $offHost->getBody());
        self::assertSame([], $this->indexNow->queuedSubmissions);

        self::assertSame(303, $owner->post($this->url() . '/submit', ['urls' => "https://shop.acme.test/a\n\nhttps://shop.acme.test/a\nhttps://shop.acme.test/b"])->getStatusCode());
        self::assertSame(['https://shop.acme.test/a', 'https://shop.acme.test/b'], $this->indexNow->queuedSubmissions[0]['urls'], 'Duplicates and blank lines are dropped.');

        $this->service()->runSubmit($this->s->acmeProject->id, $this->indexNow->queuedSubmissions[0]['urls'], 'manual', $this->s->owner->id);
        self::assertSame([['https://shop.acme.test/a', 'https://shop.acme.test/b']], $this->sent);
        self::assertMatchesRegularExpression('#By hand</td>\s*<td class="num">2</td>\s*<td><span class="badge badge-ok">200</span> Accepted\.#', (string) $owner->get($this->url())->getBody());
    }

    public function testSitemapFirstRecordsThenSubmitsChanges(): void
    {
        $this->verified();
        $project = $this->s->acmeProject->id;
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(422, $owner->post($this->url() . '/sitemap', ['sitemap_url' => 'https://evil.test/sitemap.xml', 'auto_submit' => '1'])->getStatusCode());
        self::assertSame(303, $owner->post($this->url() . '/sitemap', ['sitemap_url' => 'https://shop.acme.test/sitemap.xml', 'auto_submit' => '1'])->getStatusCode());
        self::assertSame([$project], $this->indexNow->queuedSitemaps);

        $this->sitemap = $this->urlset(['https://shop.acme.test/' => '2026-09-01', 'https://shop.acme.test/a' => '2026-09-01', 'https://other.test/x' => null]);
        $this->service()->runSitemap($project);
        self::assertSame([], $this->sent, 'The first check only records the URLs.');
        self::assertCount(2, $this->indexNow->urls[$project], 'URLs of other hosts are ignored.');

        $this->sitemap = $this->urlset(['https://shop.acme.test/' => '2026-09-01', 'https://shop.acme.test/a' => '2026-10-01', 'https://shop.acme.test/new' => null]);
        $this->service()->runSitemap($project);
        self::assertSame([['https://shop.acme.test/a', 'https://shop.acme.test/new']], $this->sent, 'Changed and new URLs are submitted.');
        $this->service()->runSitemap($project);
        self::assertCount(1, $this->sent, 'Nothing changed since.');
        self::assertNull($this->indexNow->settings($project)?->lastError);
    }

    public function testSubdirectoryKeyCoversOnlyAddressesUnderItsPath(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $base = $this->url();
        $owner->get($base);
        $owner->post($base . '/setup');
        $key = $this->indexNow->settings($this->s->acmeProject->id)->key ?? '';
        $this->http->on('GET', 'https://shop.acme.test/', static fn(): HttpResponse => new HttpResponse(404, 'Not found'));
        $this->http->on('GET', 'https://shop.acme.test/blog/' . $key . '.txt', static fn(): HttpResponse => new HttpResponse(200, $key));
        self::assertStringContainsString('name="key_path"', (string) $owner->get($base)->getBody());

        // The root does not serve the key, and unsafe paths are refused before any request.
        $root = $owner->post($base . '/verify', ['key_path' => '']);
        self::assertSame(422, $root->getStatusCode());
        self::assertStringContainsString('https://shop.acme.test/' . $key . '.txt does not serve the key', (string) $root->getBody());
        foreach (['/blog/../admin', '/a%2Fb', '/blog?x=1', 'https://evil.test/'] as $unsafe) {
            $owner->get($base);
            $refused = $owner->post($base . '/verify', ['key_path' => $unsafe]);
            self::assertSame(422, $refused->getStatusCode(), $unsafe);
            self::assertStringContainsString('Enter a site path such as /blog/', (string) $refused->getBody(), $unsafe);
            self::assertStringContainsString('aria-invalid="true"', (string) $refused->getBody(), $unsafe);
        }
        self::assertNull($this->indexNow->settings($this->s->acmeProject->id)?->verifiedAt);

        $owner->get($base);
        self::assertSame(303, $owner->post($base . '/verify', ['key_path' => 'blog'])->getStatusCode());
        $settings = $this->indexNow->settings($this->s->acmeProject->id);
        self::assertSame(['shop.acme.test', '/blog/'], [$settings?->host, $settings?->keyPath]);
        $page = (string) $owner->get($base)->getBody();
        self::assertStringContainsString('Key file found at https://shop.acme.test/blog/' . $key . '.txt', $page);
        self::assertStringContainsString('only addresses under that path are submitted', $page);
        self::assertStringContainsString('value="/blog/"', $page);

        // Manual submissions must be under /blog/; /blogger/ is a different path.
        $outside = $owner->post($base . '/submit', ['urls' => "https://shop.acme.test/blog/post\nhttps://shop.acme.test/blogger/x\nhttps://shop.acme.test/shop"]);
        self::assertSame(422, $outside->getStatusCode());
        self::assertStringContainsString('Only addresses on https://shop.acme.test/blog/ can be submitted; 2 line(s)', (string) $outside->getBody());
        $owner->get($base);
        self::assertSame(303, $owner->post($base . '/submit', ['urls' => "https://shop.acme.test/blog/\nhttps://shop.acme.test/blog/post"])->getStatusCode());
        $this->service()->runSubmit($this->s->acmeProject->id, [...$this->indexNow->queuedSubmissions[0]['urls'], 'https://shop.acme.test/shop'], 'manual', $this->s->owner->id);
        self::assertSame([['https://shop.acme.test/blog/', 'https://shop.acme.test/blog/post']], $this->sent);
        self::assertSame(['https://shop.acme.test/blog/' . $key . '.txt'], $this->locations);

        // Sitemap checks track only URLs under the path; the sitemap itself may be at the root.
        $project = $this->s->acmeProject->id;
        $owner->get($base);
        self::assertSame(303, $owner->post($base . '/sitemap', ['sitemap_url' => 'https://shop.acme.test/sitemap.xml', 'auto_submit' => '1'])->getStatusCode());
        $this->sitemap = $this->urlset(['https://shop.acme.test/blog/a' => '2026-09-01', 'https://shop.acme.test/shop/b' => '2026-09-01']);
        $this->service()->runSitemap($project);
        self::assertSame(['https://shop.acme.test/blog/a'], array_keys($this->indexNow->urls[$project]));
        $this->sitemap = $this->urlset(['https://shop.acme.test/blog/a' => '2026-10-01', 'https://shop.acme.test/shop/b' => '2026-10-01', 'https://shop.acme.test/blog/new' => null]);
        $this->service()->runSitemap($project);
        self::assertSame([['https://shop.acme.test/blog/', 'https://shop.acme.test/blog/post'], ['https://shop.acme.test/blog/a', 'https://shop.acme.test/blog/new']], $this->sent);
        self::assertSame(array_fill(0, 2, 'https://shop.acme.test/blog/' . $key . '.txt'), $this->locations);

        // Re-verifying at the root clears the path; queued URLs outside the new scope are logged, not sent.
        $this->http->on('GET', 'https://shop.acme.test/' . $key . '.txt', static fn(): HttpResponse => new HttpResponse(200, $key));
        $owner->get($base);
        self::assertSame(303, $owner->post($base . '/verify', ['key_path' => '/'])->getStatusCode());
        self::assertSame('/', $this->indexNow->settings($project)?->keyPath);
        $this->indexNow->markVerified($project, 'www.shop.acme.test');
        $this->service()->runSubmit($project, ['https://shop.acme.test/blog/post'], 'manual', null);
        self::assertCount(2, $this->sent);
        self::assertStringContainsString('no URL is covered by the verified key file', (string) $owner->get($base)->getBody());
    }

    public function testOnlyManagersSetItUpAndOtherWorkspacesGetNotFound(): void
    {
        $viewer = $this->s->loggedIn($this->s->viewer);
        $page = (string) $viewer->get($this->url())->getBody();
        self::assertStringContainsString('A workspace owner or admin can set it up.', $page);
        self::assertSame(403, $viewer->post($this->url() . '/setup')->getStatusCode());

        $this->verified();
        self::assertSame(403, $viewer->get($this->url() . '/key-file')->getStatusCode());
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        foreach (['', '/key-file'] as $path) {
            self::assertSame(404, $rival->get($this->url() . $path)->getStatusCode(), $path);
        }
    }

    private function verified(): void
    {
        $this->indexNow->create($this->s->acmeProject->id, $this->s->acme->id, str_repeat('b', 32));
        $this->indexNow->markVerified($this->s->acmeProject->id, 'shop.acme.test');
    }

    /**
     * @param array<string, ?string> $urls
     */
    private function urlset(array $urls): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ($urls as $loc => $lastmod) {
            $xml .= '<url><loc>' . htmlspecialchars($loc) . '</loc>' . ($lastmod !== null ? '<lastmod>' . $lastmod . '</lastmod>' : '') . '</url>';
        }

        return $xml . '</urlset>';
    }

    private function service(): IndexNowService
    {
        $service = $this->s->container->get(IndexNowService::class);
        self::assertInstanceOf(IndexNowService::class, $service);

        return $service;
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/indexnow';
    }
}

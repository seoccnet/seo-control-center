<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleIdeas;
use SeoControlCenter\Tests\Support\Fakes\InMemoryContentItems;
use SeoControlCenter\Tests\Support\Scenario;

/** Research → Content inventory (ADR-042): CMS records, CSV import, reconciliation and the check before new content. */
final class ContentInventoryHttpTest extends TestCase
{
    private Scenario $s;

    private string $base;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->base = '/projects/' . $this->s->acmeProject->publicId . '/inventory';
        $this->items()->records[$this->s->acmeProject->id] = [
            ['source' => 'crawl', 'url' => 'https://shop.acme.test/blog/mold-resistant-cultivars', 'ref' => 'c1', 'title' => 'Mold resistant cultivars', 'status' => 'ok', 'observed_at' => '2026-10-20 10:00:00', 'detail' => 'HTTP 200', 'target' => null, 'noindex' => false],
            ['source' => 'crawl', 'url' => 'https://shop.acme.test/old-guide', 'ref' => 'c2', 'title' => null, 'status' => 'redirect', 'observed_at' => '2026-10-20 10:00:00', 'detail' => 'HTTP 301 to https://shop.acme.test/blog/mold-resistant-cultivars', 'target' => 'https://shop.acme.test/blog/mold-resistant-cultivars'],
        ];
    }

    public function testADraftWithoutRankingsStopsADuplicateArticleIdea(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertSame(303, $this->post($owner, $this->base . '/items', ['title' => 'How to dry and cure pepper', 'url' => '', 'status' => 'draft'])->getStatusCode());
        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('How to dry and cure pepper', $html);
        self::assertStringContainsString('Draft in CMS', $html);
        self::assertStringContainsString('Redirects', $html, 'Crawled pages are in the same list with their own state.');

        $this->ideas()->replaceIdeas($this->s->acmeProject->id, $this->s->acme->id, 1, [self::idea('dry pepper'), self::idea('mold on pepper')], '2026-10-20 10:00:00');
        $ideas = (string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/article-ideas')->getBody();
        self::assertSame(1, substr_count($ideas, 'Already in your content inventory'), 'Only the idea the draft covers is flagged.');
        self::assertStringContainsString('Draft in CMS: How to dry and cure pepper', strip_tags($ideas));

        $this->items()->records[$this->s->acmeProject->id][] = ['source' => 'crawl', 'url' => 'https://shop.acme.test/seeds/brandywine-seeds', 'ref' => 'c3', 'title' => 'Brandywine Seeds | Organic', 'status' => 'ok', 'observed_at' => '2026-10-20 10:00:00', 'detail' => 'HTTP 200', 'target' => null, 'noindex' => false];
        $this->ideas()->replaceIdeas($this->s->acmeProject->id, $this->s->acme->id, 1, [self::idea('brandywine seeds')], '2026-10-20 10:00:00');
        $shop = (string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/article-ideas')->getBody();
        self::assertStringNotContainsString('Already in your content inventory', $shop, 'A product page does not make an article idea a duplicate.');
        self::assertStringContainsString('Other pages on this topic, not articles', $shop);

        $brief = (string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/content-briefs/new?keyword=dry+pepper')->getBody();
        self::assertStringContainsString('Your content inventory already has this topic.', $brief);
    }

    public function testCsvImportUnderstandsCmsStatusesAndUpdatesByCmsId(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $csv = "post_title,permalink,post_status,post_date,id\nDrying guide,,draft,2026-11-01,1042\nBrandywine review,https://shop.acme.test/blog/brandywine,publish,2026-09-01,1043\nForeign,https://other.test/x,publish,,1044\nNo status,,,,1045";
        self::assertSame(303, $this->post($owner, $this->base . '/import', ['csv' => $csv])->getStatusCode());
        $items = $this->items()->items($this->s->acmeProject->id);
        self::assertSame(['Brandywine review' => 'published', 'Drying guide' => 'draft'], array_column(array_map(static fn($i): array => ['t' => $i->title, 's' => $i->status], $items), 's', 't'));
        self::assertStringContainsString('Skipped 2', (string) $owner->get($this->base)->getBody(), 'Off-site addresses and missing statuses are skipped and reported.');

        $this->post($owner, $this->base . '/import', ['csv' => "title,status,cms_id\nDrying guide,future,1042"]);
        $items = $this->items()->items($this->s->acmeProject->id);
        self::assertCount(2, $items, 'A known CMS ID updates its record instead of duplicating it.');
        self::assertSame('scheduled', $this->items()->findByCmsId($this->s->acmeProject->id, '1042')?->status);
        self::assertSame(422, $this->post($owner, $this->base . '/import', ['csv' => "url,status\nhttps://shop.acme.test/a,draft"])->getStatusCode());
    }

    public function testPeopleConfirmUncertainMatchesAndEditsAreVersioned(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $this->post($owner, $this->base . '/items', ['title' => 'Best mold resistant cultivars', 'status' => 'published']);
        $item = $this->items()->items($this->s->acmeProject->id)[0];
        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('Possible match', $html);
        self::assertStringContainsString('This is the page', $html);

        self::assertSame(303, $this->post($owner, $this->base . '/items/' . $item->publicId . '/link', ['url' => 'https://shop.acme.test/blog/mold-resistant-cultivars', 'version' => '1'])->getStatusCode());
        self::assertSame('https://shop.acme.test/blog/mold-resistant-cultivars', $this->items()->find($this->s->acmeProject->id, $item->publicId)?->linkedUrl);
        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringNotContainsString('Possible match', $html, 'Once linked, the record joins the page row.');
        self::assertStringContainsString('linked by a person', $html);

        self::assertSame(409, $this->post($owner, $this->base . '/items/' . $item->publicId, ['title' => 'Stale edit', 'status' => 'draft', 'version' => '1'])->getStatusCode(), 'An edit from an old version is refused.');
        self::assertSame(303, $this->post($owner, $this->base . '/items/' . $item->publicId, ['title' => 'Fresh edit', 'status' => 'published', 'version' => '2'])->getStatusCode());
        self::assertSame(303, $this->post($owner, $this->base . '/items/' . $item->publicId . '/delete')->getStatusCode());
        self::assertSame([], $this->items()->items($this->s->acmeProject->id));
        self::assertContains('inventory.item_linked', $this->s->audit()->actions());
    }

    public function testViewersSeeTheInventoryButCannotChangeIt(): void
    {
        $viewer = $this->s->loggedIn($this->s->viewer);
        $html = (string) $viewer->get($this->base)->getBody();
        self::assertStringContainsString('Content inventory', $html);
        self::assertStringNotContainsString('Add a CMS record', $html);
        self::assertSame(403, $this->post($viewer, $this->base . '/items', ['title' => 'X', 'status' => 'draft'])->getStatusCode());
        self::assertSame(403, $this->post($viewer, $this->base . '/import', ['csv' => "title,status\nX,draft"])->getStatusCode());
        self::assertSame([], $this->items()->items($this->s->acmeProject->id));
    }

    /** @param array<string, string> $data */
    private function post(Browser $browser, string $path, array $data = []): ResponseInterface
    {
        $browser->get('/account');

        return $browser->post($path, $data);
    }

    private static function idea(string $main): ArticleIdea
    {
        return new ArticleIdea(hash('sha256', $main), ArticleIdea::NEW, $main, 1000, [['keyword' => $main, 'volume' => 1000, 'difficulty' => 10]], [['domain' => 'rival.test', 'found' => false, 'url' => 'https://rival.test/blog/x', 'position' => 3, 'traffic' => 10.0, 'keywords' => 1]], null, [
            'traffic' => ['low' => 10, 'expected' => 40, 'high' => 90],
            'difficulty' => ['level' => 'low', 'reasons' => []],
            'backlinks' => null,
            'months' => ['low' => 1, 'high' => 3, 'basis' => 'An assumption.'],
        ], 1.0, []);
    }

    private function items(): InMemoryContentItems
    {
        return $this->s->container->make(InMemoryContentItems::class);
    }

    private function ideas(): InMemoryArticleIdeas
    {
        return $this->s->container->make(InMemoryArticleIdeas::class);
    }
}

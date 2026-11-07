<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\ContentBriefs\BriefBuilder;
use SeoControlCenter\Modules\ContentBriefs\BriefInput;
use SeoControlCenter\Modules\ContentBriefs\BriefRepository;
use SeoControlCenter\Modules\ContentBriefs\BriefService;
use SeoControlCenter\Modules\Pages\PageQuery;
use SeoControlCenter\Modules\Pages\PageSummary;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Scenario;

final class ContentBriefsHttpTest extends TestCase
{
    public function testWritingWorkflowEvidenceEscapingConflictAndExports(): void
    {
        $s = new Scenario();
        $url = 'https://shop.acme.test/Guide';
        $pages = $s->container->make(InMemoryPages::class);
        $pages->pages[$s->acmeProject->id][] = new PageSummary($url, new Metrics(42, 1000, 8.0), null);
        $pages->queryRows[$s->acmeProject->id][] = new PageQuery('outdoor shoes', $url, new Metrics(21, 600, 8.0));
        $browser = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/content-briefs';
        $seed = $browser->get($base . '/new?url=' . rawurlencode($url));
        self::assertSame(200, $seed->getStatusCode());
        self::assertStringContainsString('outdoor shoes', (string) $seed->getBody());
        $input = ['title' => '<script>Brief Ω</script>', 'keyword' => 'outdoor shoes', 'url' => $url, 'status' => 'draft', 'outline' => "H1: Shoes\nH2: Materials", 'notes' => '<img src=x onerror=alert(1)>'];
        self::assertSame(303, $browser->post($base, $input)->getStatusCode());
        $repo = $s->container->make(BriefRepository::class);
        $brief = $repo->list($s->acmeProject, '', '', 25, 0)[0];
        $self = $base . '/' . $brief->publicId;
        self::assertStringContainsString('42 clicks', implode(' ', $brief->evidence));
        self::assertStringContainsString('Bing (weekly buckets): unavailable', implode(' ', $brief->evidence));
        $html = (string) $browser->get($self)->getBody();
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));
        self::assertSame([], AccessibilityTest::problems((string) $browser->get($self . '/edit')->getBody(), true));
        self::assertSame(303, $browser->post($self, ['status' => 'in_progress', 'revision' => '1'] + $input)->getStatusCode());
        $browser->get($self . '/edit');
        $conflict = $browser->post($self, ['outline' => 'Unsaved work must stay visible', 'revision' => '1'] + $input);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertStringContainsString('Unsaved work must stay visible', (string) $conflict->getBody());
        self::assertStringContainsString('disabled', (string) $conflict->getBody());
        self::assertSame(2, $repo->find($s->acmeProject, $brief->publicId)?->revision);
        $browser->get($self . '/edit');
        self::assertSame(303, $browser->post($self, ['status' => 'published', 'revision' => '2', 'url' => 'https://shop.acme.test/New'] + $input)->getStatusCode());
        $saved = $repo->find($s->acmeProject, $brief->publicId);
        self::assertNotNull($saved);
        self::assertSame($brief->evidence, $saved->evidence);
        self::assertStringContainsString('Record an SEO change', (string) $browser->get($self)->getBody());
        self::assertSame([], $s->container->make(\SeoControlCenter\Modules\Annotations\AnnotationRepository::class)->list($s->acmeProject, null, null, 25, 0));
        $print = $browser->get($self . '/print');
        self::assertSame(200, $print->getStatusCode());
        self::assertSame([], AccessibilityTest::problems((string) $print->getBody(), true));
        self::assertStringContainsString('browser’s Print command', (string) $print->getBody());
        $export = $browser->get($self . '/export');
        self::assertSame('no-store', $export->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('attachment;', $export->getHeaderLine('Content-Disposition'));
        self::assertStringContainsString('Original evidence snapshot', (string) $export->getBody());
        $annotation = $browser->get('/projects/' . $s->acmeProject->publicId . '/annotations?' . http_build_query(['title' => 'Published: Shoes', 'kind' => 'content', 'url' => $url]));
        self::assertStringContainsString('value="Published: Shoes"', (string) $annotation->getBody());
        self::assertContains('project.brief_created', $s->audit()->actions());
        self::assertContains('project.brief_updated', $s->audit()->actions());
    }

    public function testQueueDaysAndPublicationCountdown(): void
    {
        $clock = new \SeoControlCenter\Core\Support\FrozenClock(new \DateTimeImmutable('2026-10-01 08:00:00 UTC'));
        $s = new Scenario(clock: $clock);
        $browser = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/content-briefs';
        $browser->get($base . '/new');
        self::assertSame(303, $browser->post($base, ['title' => 'Gravel guide', 'keyword' => 'gravel bike', 'url' => '', 'status' => 'draft'])->getStatusCode());
        self::assertSame(303, $browser->post($base, ['title' => 'Shoe care', 'keyword' => 'shoe care', 'url' => 'https://shop.acme.test/care', 'status' => 'in_progress'])->getStatusCode());
        $repo = $s->container->make(BriefRepository::class);
        $care = $repo->list($s->acmeProject, 'in_progress', '', 25, 0)[0];
        $clock->advance('+4 days');
        self::assertSame(303, $browser->post($base . '/' . $care->publicId, ['title' => 'Shoe care', 'keyword' => 'shoe care', 'url' => 'https://shop.acme.test/care', 'status' => 'published', 'revision' => '1'])->getStatusCode());
        self::assertSame('2026-10-05 08:00:00', $repo->find($s->acmeProject, $care->publicId)?->publishedAt);
        $clock->advance('+16 days');

        $html = (string) $browser->get($base)->getBody();
        self::assertMatchesRegularExpression('#Draft</span><strong>1</strong>#', $html);
        self::assertMatchesRegularExpression('#Published</span><strong>1</strong>#', $html);
        self::assertStringContainsString('<strong>20</strong> days in the queue', $html);
        self::assertStringContainsString('Published 2026-10-05 after 4 days', $html);
        self::assertStringContainsString('<strong>Day 16</strong> of 42', $html, 'An updated page uses the content window.');
        self::assertStringContainsString('In the effect window', $html);
        $detail = (string) $browser->get($base . '/' . $care->publicId)->getBody();
        self::assertStringContainsString('Since publication', $detail);
        self::assertSame([], AccessibilityTest::problems($detail, true));
        self::assertSame([], AccessibilityTest::problems($html, true));

        // Leaving Published clears the date; publishing again starts a new countdown.
        self::assertSame(303, $browser->post($base . '/' . $care->publicId, ['title' => 'Shoe care', 'keyword' => 'shoe care', 'url' => 'https://shop.acme.test/care', 'status' => 'in_progress', 'revision' => '2'])->getStatusCode());
        self::assertNull($repo->find($s->acmeProject, $care->publicId)?->publishedAt);
    }

    public function testValidationNewPageMissingDataLiteralSearchAndPagination(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/content-briefs';
        $browser->get($base . '/new');
        $input = ['title' => 'Guide 100%_Ω', 'keyword' => 'trail shoes', 'status' => 'draft', 'url' => 'https://shop.acme.test/new'];
        foreach ([['title' => ''], ['keyword' => str_repeat('x', 201)], ['status' => 'automatic'], ['url' => 'https://other.test/'], ['url' => 'https://user@shop.acme.test/'], ['url' => 'https://shop.acme.test/#x'], ['url' => 'https://shop.acme.test:443/'], ['intent' => ['injected']], ['notes' => str_repeat('x', 4001)]] as $bad) {
            $response = $browser->post($base, $bad + $input);
            self::assertSame(422, $response->getStatusCode(), json_encode($bad, JSON_THROW_ON_ERROR));
            self::assertSame([], AccessibilityTest::problems((string) $response->getBody(), true));
        }
        self::assertSame(303, $browser->post($base, $input)->getStatusCode());
        $repo = $s->container->make(BriefRepository::class);
        $first = $repo->list($s->acmeProject, '', '', 25, 0)[0];
        self::assertStringContainsString('not in the stored page catalog', implode(' ', $first->evidence));
        for ($i = 0; $i < 26; ++$i) {
            $repo->create($s->acmeProject, BriefInput::fromArray(['title' => 'Other ' . $i, 'status' => 'published'] + $input, $s->acmeProject), [], $s->owner->id);
        }
        $service = $s->container->make(BriefService::class);
        self::assertSame(1, $service->listing($s->acmeProject, search: '%_Ω')['total']);
        self::assertSame(26, $service->listing($s->acmeProject, 'published')['total']);
        self::assertCount(2, $service->listing($s->acmeProject, page: 2)['items']);
        self::assertSame(2, $service->listing($s->acmeProject, page: 999)['page']);
        self::assertSame(200, $browser->get($base . '?status=published&page=2')->getStatusCode());
        self::assertSame(200, $browser->get('/projects/' . $s->acmeProject->publicId . '/annotations?kind[]=bad')->getStatusCode());
    }

    public function testTrackedKeywordEvidenceAndSourceIsolation(): void
    {
        $s = new Scenario();
        $keywords = $s->container->make(KeywordRepository::class);
        $keywords->add($s->acmeProject->id, ['trail shoes'], KeywordLocale::default(), $s->owner->id);
        $keyword = $keywords->page($s->acmeProject->id, $s->acme->id, 10, 0)[0];
        $seed = $s->container->make(BriefBuilder::class)->build($s->acmeProject, keywordId: $keyword->publicId);
        self::assertSame('trail shoes', $seed['input']['keyword']);
        self::assertStringContainsString('Search volume: unavailable', implode(' ', $seed['evidence']));
        self::assertStringContainsString('No saved rank check', implode(' ', $seed['evidence']));
        $browser = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/content-briefs';
        self::assertSame(200, $browser->get($base . '/new?keyword_id=' . $keyword->publicId)->getStatusCode());
        self::assertSame(404, $browser->get('/projects/' . $s->acmeOther->publicId . '/content-briefs/new?keyword_id=' . $keyword->publicId)->getStatusCode());
    }

    public function testBriefIncludesSavedSerpAuditLinksCannibalizationAndOpportunity(): void
    {
        $s = new Scenario();
        $project = $s->acmeProject;
        $url = 'https://shop.acme.test/trail-shoes';
        $donor = 'https://shop.acme.test/trail-guide';
        $keywords = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords::class);
        $keywords->add($project->id, ['trail shoes'], KeywordLocale::default(), $s->owner->id);
        $row = $keywords->keywords[$project->id][0];
        $date = $s->container->make(Clock::class)->now()->format('Y-m-d');
        $rankings = $s->container->make(\SeoControlCenter\Domain\Rankings\RankingRepository::class);
        $rankings->storeSnapshot(new \SeoControlCenter\Domain\Rankings\RankSnapshot($row['id'], $project->id, \SeoControlCenter\Domain\Rankings\SerpDevice::Desktop, $date, 8, 8, $url, [], [['position' => 1, 'domain' => 'competitor.test', 'url' => 'https://competitor.test/guide']]));
        $pages = $s->container->make(InMemoryPages::class);
        $pages->pages[$project->id][] = new PageSummary($url, new Metrics(30, 500, 8), null);
        $pages->queryRows[$project->id] = [new PageQuery('trail shoes', $url, new Metrics(20, 300, 8)), new PageQuery('trail shoes', 'https://shop.acme.test/other-shoes', new Metrics(10, 200, 12))];
        $audits = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit::class);
        $audit = $audits->create($project->id, $project->workspaceId, $url, 100, 'manual', $s->owner->id);
        $audits->pages[$audit->id] = [1 => new \SeoControlCenter\Modules\SiteAudit\AuditPage(1, $url, 1, 'done', 200, 'text/html', title: 'Trail shoes buying guide', description: 'Choose shoes for your terrain.', h1: 'Trail shoes'), 2 => new \SeoControlCenter\Modules\SiteAudit\AuditPage(2, $donor, 1, 'done', 200, 'text/html', title: 'Trail shoes and routes'), 3 => new \SeoControlCenter\Modules\SiteAudit\AuditPage(3, 'https://shop.acme.test/', 0, 'done', 200, 'text/html'), 4 => new \SeoControlCenter\Modules\SiteAudit\AuditPage(4, 'https://shop.acme.test/blog', 1, 'done', 200, 'text/html')];
        $audits->links[$audit->id] = ['3:2' => [3, 2], '4:2' => [4, 2]];
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 1, 'notice' => 0], [['pageId' => 1, 'type' => \SeoControlCenter\Modules\SiteAudit\IssueType::MissingDescription, 'detail' => 'Review snippet']], null);
        $opportunities = $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities::class);
        $opportunity = new \SeoControlCenter\Domain\Opportunities\StoredOpportunity(\SeoControlCenter\Core\Support\Uuid::v7(), \SeoControlCenter\Domain\Opportunities\OpportunityType::QuickWin, 'Improve trail shoes', 'Page is close to the top.', [['label' => 'Keyword', 'value' => 'trail shoes'], ['label' => 'Page', 'value' => $url]], 50, 80, 2, 20, 'open', $date, $date);
        $opportunities->stored[$project->id]['quick'] = $opportunity;
        $seed = $s->container->make(BriefBuilder::class)->build($project, keywordId: $row['public_id'], opportunityId: $opportunity->publicId);
        self::assertSame($url, $seed['input']['url']);
        self::assertSame('Trail shoes buying guide', $seed['input']['seo_title']);
        self::assertSame('Choose shoes for your terrain.', $seed['input']['meta_description']);
        self::assertStringContainsString($donor, $seed['input']['internal_links']);
        self::assertStringContainsString('Possible cannibalization', $seed['input']['notes']);
        self::assertStringContainsString('Writing rules: Keep the words that match the page’s intent', $seed['input']['notes']);
        self::assertStringContainsString('Do not put fixed prices in titles', $seed['input']['notes']);
        $evidence = implode(' ', $seed['evidence']);
        self::assertStringContainsString('https://competitor.test/guide', $evidence);
        self::assertStringContainsString('Review snippet', $evidence);
        self::assertStringContainsString('Last detected ' . $date, $evidence);
        self::assertStringContainsString('Rank check ' . $date, $evidence);
        self::assertStringContainsString('Latest completed crawl', $evidence);
        self::assertStringContainsString('Unclassified', $seed['input']['intent']);
        self::assertSame([], $keywords->requests);
        self::assertSame([], $audits->queuedCrawls);
        $browser = $s->loggedIn($s->owner);
        $base = '/projects/' . $project->publicId . '/content-briefs';
        $browser->get($base . '/new?keyword_id=' . $row['public_id']);
        self::assertSame(303, $browser->post($base, ['title' => 'New separate page', 'keyword' => 'new trail shoes', 'url' => '', 'status' => 'draft', 'keyword_id' => $row['public_id'], 'opportunity_id' => $opportunity->publicId])->getStatusCode());
        $saved = $s->container->make(BriefRepository::class)->list($project, '', '', 25, 0)[0];
        self::assertNull($saved->input->url);
        self::assertContains('Initial target: new trail shoes | URL new page not specified', $saved->evidence);
        self::assertStringNotContainsString('Page snapshot:', implode(' ', $saved->evidence));
        $opportunities->stored[$project->id]['quick'] = new \SeoControlCenter\Domain\Opportunities\StoredOpportunity($opportunity->publicId, $opportunity->type, $opportunity->title, $opportunity->explanation, array_fill(0, 12, ['label' => 'Large evidence', 'value' => str_repeat('Ω', 2500)]), 50, 80, 2, 20, 'open', $date, $date);
        $bounded = $s->container->make(BriefBuilder::class)->build($project, 'New target', opportunityId: $opportunity->publicId, inferUrl: false)['evidence'];
        self::assertContains('Initial target: New target | URL new page not specified', $bounded);
        self::assertLessThanOrEqual(30000, strlen(implode('', $bounded)));
        self::assertLessThanOrEqual(85, count($bounded));
        self::assertStringContainsString('limit', $bounded[array_key_last($bounded)]);
    }
}

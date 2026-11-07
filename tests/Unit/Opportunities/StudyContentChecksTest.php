<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\ChangeBundles\BundleRules;
use SeoControlCenter\Domain\ContentInventory\OverlapFinder;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\PlaybookLibrary;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Rules\DuplicatePagesRule;
use SeoControlCenter\Domain\Opportunities\Rules\SiteIssueRule;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\IssueDetector;
use SeoControlCenter\Modules\SiteAudit\IssueType;

/**
 * Content checks from what published SEO tests measured (0.44.0): the page's
 * own structured data type, answered buyer questions and specification
 * tables on product and category pages, and merging near-duplicate pages.
 */
final class StudyContentChecksTest extends TestCase
{
    private const array CITED = ['questions' => 0, 'tables' => 0, 'lists' => 0, 'figures' => 0, 'lead_words' => null, 'updated' => false];

    public function testListsOfTermsCountAsTablesAndAccordionQuestionsAsHeadings(): void
    {
        $html = '<html><body><nav><dl><dt>A</dt><dd>1</dd><dt>B</dt><dd>2</dd><dt>C</dt><dd>3</dd></dl></nav><main>'
            . '<dl><dt>NPK</dt><dd>25 %</dd><dt>Potassium</dt><dd>1 %</dd><dt>Flowering</dt><dd>60–70 days</dd></dl>'
            . '<dl><dt>Pack</dt><dd>3 seeds</dd></dl>'
            . '<details><summary>Is this cultivar good for beginners?</summary><p>Yes.</p></details>'
            . '</main></body></html>';
        $page = HtmlPage::parse($html, 'https://shop.test/seeds/blue');
        self::assertSame(1, $page->citation['tables'] ?? null, 'a body list of three terms or more; menus and short lists do not count');
        self::assertSame(1, $page->citation['questions'] ?? null, 'an accordion title phrased as a question');
    }

    public function testPagesWithOnlySiteMarkupGetTheirOwnTypeNotice(): void
    {
        $found = $this->detect([
            $this->page(1, '/seeds/blue', ['BreadcrumbList', 'Organization'], 'product'),
            $this->page(2, '/blog/watering', ['WebPage', 'BreadcrumbList'], 'website'),
            $this->page(3, '/seeds/red', ['Product', 'BreadcrumbList'], 'product'),
            $this->page(4, '/blog/feeding', ['BlogPosting'], 'article'),
            $this->page(5, '/blog/tag/feeding', ['CollectionPage'], 'website'),
            $this->page(6, '/about', ['WebPage'], 'website'),
            $this->page(7, '/seeds/legacy', null, 'product'),
        ]);
        self::assertSame([
            1 => 'Marked as a product (og:type) but its markup is only BreadcrumbList, Organization; add Product markup with price and availability',
            2 => 'An article (by its address) whose markup is only WebPage, BreadcrumbList; add Article or BlogPosting markup with author and dates',
        ], $found['missing_content_markup'] ?? null, 'Marked-up products and articles, archives, other pages and unknown markup are not reported.');
        self::assertArrayNotHasKey('missing_structured_data', $found, 'pages with some markup keep the older notice quiet');
    }

    public function testProductAndCategoryPagesWithoutAnswersOrSpecsGetNotices(): void
    {
        $found = $this->detect([
            $this->page(1, '/seeds/blue', ['Product'], 'product', self::CITED),
            $this->page(2, '/seeds/red', ['Product'], 'product', ['questions' => 2] + self::CITED),
            $this->page(3, '/seeds/green', ['Product', 'FAQPage'], 'product', ['tables' => 1] + self::CITED),
            $this->page(4, '/seeds/organic', ['CollectionPage', 'ItemList'], 'website', self::CITED),
            $this->page(5, '/seeds/auto', ['CollectionPage', 'FAQPage'], 'website', self::CITED),
            $this->page(6, '/', ['WebSite', 'ItemList'], 'website', self::CITED),
            $this->page(7, '/seeds/legacy', ['Product'], 'product'),
            $this->page(8, '/blog/feeding', ['BlogPosting', 'ItemList'], 'article', self::CITED),
        ]);
        self::assertSame([
            1 => 'Product page: no heading phrased as a question and no FAQ markup',
            4 => 'Category page: no heading phrased as a question and no FAQ markup',
        ], $found['missing_questions'] ?? null, 'Answered, FAQ-marked, home, article and legacy pages are not reported.');
        self::assertSame([1, 2], array_keys($found['missing_spec_table'] ?? []), 'Products without a table; categories never.');
        foreach ([IssueType::MissingContentMarkup, IssueType::MissingQuestions, IssueType::MissingSpecTable] as $type) {
            self::assertSame('notice', $type->severity());
            self::assertContains($type->value, SiteIssueRule::GROWTH_NOTICES);
            $playbook = (new PlaybookLibrary())->for(OpportunityType::SiteIssue, 'site:' . $type->value);
            self::assertSame('tested', $playbook->tested, $type->value);
            self::assertSame([[$type === IssueType::MissingContentMarkup ? 'other' : 'content'], 'clicks'], BundleRules::fromPlaybook('site:' . $type->value));
        }
        self::assertStringContainsString('Google no longer shows FAQ rich results', IssueType::MissingQuestions->advice());
    }

    public function testNearDuplicatesShownInSearchBecomeMergeOpportunities(): void
    {
        $text = 'Blossom rot is a grey mold that grows inside dense tomato flowers when humidity stays high late in flowering, and it spreads fast once the first blossoms turn brown and soft in the middle of the cola.';
        $pairs = OverlapFinder::nearDuplicates([
            ['url' => 'https://shop.test/blog/blossom-rot', 'title' => 'Blossom rot', 'text' => $text],
            ['url' => 'https://shop.test/blog/mold-on-pepper/', 'title' => 'Mold on pepper', 'text' => $text . ' Most growers notice it too late.'],
            ['url' => 'https://shop.test/blog/other', 'title' => 'Other', 'text' => 'Something completely different about watering seedlings in small pots during the first two weeks after germination, with numbers.'],
        ]);
        self::assertCount(1, $pairs);
        $pair = ['key' => $pairs[0]['key'], 'members' => $pairs[0]['members'], 'evidence' => $pairs[0]['evidence'], 'uncertainty' => $pairs[0]['uncertainty'], 'score' => $pairs[0]['score'], 'decision' => null];
        $rule = new DuplicatePagesRule();

        self::assertSame([], $rule->evaluate(new ProjectSignals(nearDuplicates: [$pair])), 'neither page is shown in search');
        $found = $rule->evaluate(new ProjectSignals(pages: [
            new DimensionRow('https://www.shop.test/blog/mold-on-pepper', new Metrics(30, 900, 6.0)),
            new DimensionRow('https://shop.test/blog/blossom-rot', new Metrics(4, 200, 11.0)),
        ], nearDuplicates: [$pair]));
        self::assertCount(1, $found);
        self::assertSame(OpportunityType::Cannibalization, $found[0]->type);
        self::assertSame('duplicate:' . $pair['key'], $found[0]->subject);
        self::assertSame('Merge near-duplicate pages into /blog/mold-on-pepper/', $found[0]->title, 'the page with most clicks is kept, matched across www and the trailing slash');
        self::assertSame('https://shop.test/blog/blossom-rot', $found[0]->evidence[1]['value']);
        self::assertStringContainsString('/blog/mold-on-pepper/: 30 clicks, 900 impressions', $found[0]->evidence[3]['value']);
        self::assertSame(2, $found[0]->urlsTouched);

        self::assertSame(['near_duplicates', 'search_console'], $rule->requires($found[0]));
        self::assertSame(['query_splits', 'search_console'], SignalRequirements::for('cannibalization', 'query:shoes'));
        self::assertSame('cannibalization:duplicate', PlaybookLibrary::key(OpportunityType::Cannibalization, $found[0]->subject));
        $playbook = (new PlaybookLibrary())->for(OpportunityType::Cannibalization, $found[0]->subject, $found[0]->evidence);
        self::assertSame(['Merge near-duplicate pages into one stronger page', 'varies'], [$playbook->title, $playbook->tested]);
        self::assertStringContainsString('fold https://shop.test/blog/blossom-rot into it', $playbook->goal);
        self::assertSame([['redirect', 'canonical'], 'clicks'], BundleRules::fromPlaybook('cannibalization:duplicate'));
    }

    /**
     * @param list<string>|null $schema
     * @param array{questions: int, tables: int, lists: int, figures: int, lead_words: ?int, updated: bool}|null $citation
     */
    private function page(int $id, string $path, ?array $schema, ?string $og, ?array $citation = null): AuditPage
    {
        return new AuditPage($id, 'https://shop.test' . $path, 1, 'done', 200, 'text/html', title: 'T' . $id, description: 'D' . $id, h1Count: 1, h1: 'H' . $id, wordCount: 400, schemaTypes: $schema, ogType: $og, hasAuthor: true, sourceLinks: 1, citation: $citation);
    }

    /**
     * @param list<AuditPage> $pages
     * @return array<string, array<int, ?string>>
     */
    private function detect(array $pages): array
    {
        $inbound = [];
        foreach ($pages as $page) {
            $inbound[$page->id] = 1;
        }
        $found = [];
        foreach (IssueDetector::detect($pages, $inbound, false)['issues'] as $issue) {
            $found[$issue['type']->value][$issue['pageId']] = $issue['detail'];
        }

        return $found;
    }
}

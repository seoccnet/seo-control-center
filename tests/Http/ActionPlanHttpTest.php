<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Action plans on findings, page plans and tasks (ADR-031).
 */
final class ActionPlanHttpTest extends TestCase
{
    private Scenario $s;

    /** @var array<string, string> subject => public ID */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $repo = $this->s->container->get(InMemoryOpportunities::class);
        $repo->sync($this->s->acmeProject->id, [
            new Opportunity(OpportunityType::InternalLinks, 'page:https://shop.acme.test/a', 'Link to /a', 'Few internal links point here.', [['label' => 'Page', 'value' => 'https://shop.acme.test/a']], 60, 70, 1, 40),
            new Opportunity(OpportunityType::LowCtr, 'query:red <shoes>', 'Improve CTR', 'Low CTR.', [['label' => 'Query', 'value' => 'red <shoes>'], ['label' => 'Page', 'value' => 'https://shop.acme.test/red']], 55, 60, 1),
            new Opportunity(OpportunityType::SiteIssue, 'crux:https://shop.acme.test/slow', 'Improve mobile Core Web Vitals', 'Slow.', [['label' => 'URL', 'value' => 'https://shop.acme.test/slow']], 50, 60, 2),
            new Opportunity(OpportunityType::SiteIssue, 'site:missing_title', 'Missing title on 3 pages', 'Titles are missing.', [['label' => 'Issue type', 'value' => 'missing_title']], 40, 60, 1),
        ]);
        foreach ($repo->list($this->s->acmeProject->id, 'open', null, 10, 0) as $row) {
            $this->ids[$row->subject] = $row->publicId;
        }
    }

    private function base(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/opportunities';
    }

    public function testFindingShowsAnActionPlanTailoredToItsData(): void
    {
        $viewer = $this->s->loggedIn($this->s->viewer);
        $html = (string) $viewer->get($this->base() . '/' . $this->ids['page:https://shop.acme.test/a'])->getBody();
        self::assertStringContainsString('Action plan: Send more internal links to an important page', $html);
        foreach (['When it applies', 'Before you start', 'What to change', 'What to keep', 'What to measure', 'When to revert'] as $section) {
            self::assertStringContainsString('<h4>' . $section . '</h4>', $html, $section);
        }
        self::assertStringContainsString('Add three to ten contextual links to https://shop.acme.test/a', $html);
        self::assertLessThan(strpos($html, 'Why this ranks here'), strpos($html, 'Action plan:'), 'The plan comes before the scoring explanation.');
        self::assertDoesNotMatchRegularExpression('/meta study|study entry|evidence \(E\)/i', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));
        $stored = $this->s->container->get(InMemoryOpportunities::class)->find($this->s->acmeProject->id, $this->ids['page:https://shop.acme.test/a']);
        self::assertNotNull($stored);
        self::assertSame([60, 70, 1, 40.0], [$stored->impact, $stored->confidence, $stored->effort, $stored->estimatedClicks], 'Plans never change scores.');

        $ctr = (string) $viewer->get($this->base() . '/' . $this->ids['query:red <shoes>'])->getBody();
        self::assertStringContainsString('“red &lt;shoes&gt;”', $ctr, 'Finding text is escaped.');
        self::assertStringNotContainsString('red <shoes>', $ctr);
        self::assertStringContainsString('<h4>Avoid</h4>', $ctr);

        self::assertStringContainsString('Action plan: Make a page fast for real visitors', (string) $viewer->get($this->base() . '/' . $this->ids['crux:https://shop.acme.test/slow'])->getBody());
        self::assertStringContainsString('Action plan: Add missing titles', (string) $viewer->get($this->base() . '/' . $this->ids['site:missing_title'])->getBody());

        $data = json_decode((string) $viewer->get($this->base() . '/' . $this->ids['page:https://shop.acme.test/a'] . '/data')->getBody(), true);
        self::assertIsArray($data);
        self::assertSame('internal_links', $data['action_plan']['key']);
        self::assertSame('What to change', $data['action_plan']['sections'][2]['label']);
    }

    public function testPagePlansAndTasksCarryTheSteps(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $pages = (string) $owner->get($this->base() . '/by-page')->getBody();
        self::assertStringContainsString('<strong>Next step:</strong> Add three to ten contextual links to https://shop.acme.test/a', $pages);
        $json = json_decode((string) $owner->get($this->base() . '/page-plans')->getBody(), true);
        self::assertIsArray($json);
        $compact = $json['action_plans'][$this->ids['page:https://shop.acme.test/a']];
        self::assertSame(['key', 'title', 'first_change'], array_keys($compact));
        self::assertSame('internal_links', $compact['key']);
        $task = (string) $owner->get($this->base() . '/' . $this->ids['query:red <shoes>'] . '/task')->getBody();
        self::assertStringContainsString('Action plan: Earn more clicks from the rankings you already have', $task);
    }

    public function testGrowthNoticesFromTheAuditBecomeFindingsWithTheirPlans(): void
    {
        $s = new Scenario();
        $s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryRankings::class)->projects[$s->acmeProject->id] = ['workspace_id' => $s->acme->id, 'domain' => 'shop.acme.test'];
        $audits = $s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit::class);
        $audit = $audits->create($s->acmeProject->id, $s->acmeProject->workspaceId, 'https://shop.acme.test/', 10, 'manual', null);
        $page = $audits->pendingPages($audit->id, 1)[0];
        $audits->finish($audit->id, 'completed', 100, ['error' => 0, 'warning' => 0, 'notice' => 3], [
            ['pageId' => $page->id, 'type' => \SeoControlCenter\Modules\SiteAudit\IssueType::MissingStructuredData, 'detail' => null],
            ['pageId' => $page->id, 'type' => \SeoControlCenter\Modules\SiteAudit\IssueType::DeepPage, 'detail' => '4 clicks from the start page'],
            ['pageId' => $page->id, 'type' => \SeoControlCenter\Modules\SiteAudit\IssueType::TitleTooLong, 'detail' => '70 characters'],
        ], null);
        $owner = $s->loggedIn($s->owner);
        $base = '/projects/' . $s->acmeProject->publicId . '/opportunities';
        $owner->get($base);
        self::assertSame(303, $owner->post($base . '/analyze')->getStatusCode());
        $subjects = [];
        foreach ($s->container->get(InMemoryOpportunities::class)->list($s->acmeProject->id, 'open', null, 50, 0) as $row) {
            $subjects[$row->subject] = $row->publicId;
        }
        self::assertArrayHasKey('site:missing_structured_data', $subjects);
        self::assertArrayHasKey('site:deep_page', $subjects);
        self::assertArrayNotHasKey('site:title_too_long', $subjects, 'Ordinary notices stay out of opportunities.');
        $html = (string) $owner->get($base . '/' . $subjects['site:missing_structured_data'])->getBody();
        self::assertStringContainsString('Action plan: Add structured data', $html);
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Opportunities;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\Playbook;
use SeoControlCenter\Domain\Opportunities\PlaybookLibrary;

final class PlaybookLibraryTest extends TestCase
{
    private const array SUBJECTS = ['query:shoes', 'page:https://shop.test/a', 'lost:https://ref.test', 'aio:shoes', 'llm:shoes', 'spam:domains', 'crux:https://shop.test/slow', 'site:missing_title', 'site:unknown_type', 'indexing:https://shop.test/b'];

    public function testEveryTypeAndSubjectGetsACompletePlan(): void
    {
        $library = new PlaybookLibrary();
        foreach (OpportunityType::cases() as $type) {
            foreach (self::SUBJECTS as $subject) {
                $plan = $library->for($type, $subject);
                self::assertNotSame('', $plan->title, $type->value . ' ' . $subject);
                self::assertNotSame('', $plan->goal);
                self::assertNotSame([], $plan->items('What to change'), $type->value . ' ' . $subject);
                self::assertNotSame([], $plan->items('What to measure'), $type->value . ' ' . $subject);
                $labels = array_column($plan->sections, 'label');
                self::assertSame(array_values(array_intersect(Playbook::SECTIONS, $labels)), $labels, 'Sections keep their order.');
                foreach ([...array_merge(...array_column($plan->sections, 'items')), ...$plan->cautions, ...$plan->alsoConsider, $plan->title, $plan->goal] as $text) {
                    self::assertDoesNotMatchRegularExpression('/meta study|study entry|E \d+\.\d|evidence score/i', $text);
                }
            }
        }
    }

    public function testPlansAreFilledInWithTheFindingsQueryAndPage(): void
    {
        $library = new PlaybookLibrary();
        $plan = $library->for(OpportunityType::LowCtr, 'query:red shoes', [['label' => 'Query', 'value' => 'red shoes'], ['label' => 'Page', 'value' => 'https://shop.test/red']]);
        self::assertSame('low_ctr', $plan->key);
        self::assertStringContainsString('“red shoes”', $plan->goal);
        self::assertStringContainsString('https://shop.test/red', $plan->firstChange());
        $generic = $library->for(OpportunityType::LowCtr, 'query:x');
        self::assertStringContainsString('the target query', $generic->goal);
        $pageLevel = $library->for(OpportunityType::LowCtr, 'page:shop.test/guide', [['label' => 'Page', 'value' => 'https://shop.test/guide']]);
        self::assertStringContainsString('the page’s main search queries', $pageLevel->goal);
        self::assertStringContainsString('lead with the words of the page’s main search queries', $pageLevel->firstChange());
        $value = $library->for(OpportunityType::QuickWin, 'query:x', [['label' => 'Value page', 'value' => 'https://shop.test/v (2 sessions)']]);
        self::assertStringContainsString('https://shop.test/v', $value->goal);
        self::assertStringNotContainsString('(2 sessions)', $value->goal);
    }

    public function testSubKindsGetTheirOwnPlans(): void
    {
        self::assertSame('core_web_vitals', PlaybookLibrary::key(OpportunityType::SiteIssue, 'crux:https://shop.test/'));
        self::assertSame('site:missing_title', PlaybookLibrary::key(OpportunityType::SiteIssue, 'site:missing_title'));
        self::assertSame('site_issue', PlaybookLibrary::key(OpportunityType::SiteIssue, 'site:something_new'));
        self::assertSame('link_building:lost', PlaybookLibrary::key(OpportunityType::LinkBuilding, 'lost:https://ref.test'));
        self::assertSame('ai_visibility:aio', PlaybookLibrary::key(OpportunityType::AiVisibility, 'aio:shoes'));
        self::assertSame('ai_visibility', PlaybookLibrary::key(OpportunityType::AiVisibility, 'llm:shoes'));
        $library = new PlaybookLibrary();
        self::assertSame('Add missing titles', $library->for(OpportunityType::SiteIssue, 'site:missing_title')->title);
        self::assertStringContainsString('Search Console → Manual actions', implode(' ', $library->for(OpportunityType::LinkBuilding, 'spam:links')->items('Before you start')));
        self::assertContains('Do not disavow links preventively; a broad disavow can remove links that help you.', $library->for(OpportunityType::LinkBuilding, 'spam:links')->cautions);
        self::assertStringContainsString('fixed prices', implode(' ', $library->for(OpportunityType::LowCtr, 'query:x')->cautions));
    }
}

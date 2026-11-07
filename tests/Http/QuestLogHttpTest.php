<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Quests\QuestCatalogue;
use SeoControlCenter\Modules\Quests\ShowMeTargets;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\FixedFeatures;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryQuestEvidence;
use SeoControlCenter\Tests\Support\Fakes\InMemoryQuestProgress;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemoryReports;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

final class QuestLogHttpTest extends TestCase
{
    public function testQuestLogListsChaptersRanksAndTheCurrentChapter(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $html = (string) $browser->get('/quests?chapter=impact')->getBody();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        self::assertCount(6, $doc->querySelectorAll('.quest-chapters > a'));
        self::assertSame('/quests?chapter=impact', $doc->querySelector('.quest-chapters a[aria-current="page"]')?->getAttribute('href'));
        self::assertStringContainsString('Did It Work?', $html);
        self::assertStringContainsString('Specialist', $html);
        self::assertNotNull($doc->querySelector('progress[aria-label="Campaign quests resolved"]'));
        // Unknown chapters fall back to the current quest's chapter instead of failing.
        self::assertSame(200, $browser->get('/quests?chapter=nope')->getStatusCode());
    }

    public function testQuestDetailShowsLearningContentShowMeAndSkip(): void
    {
        $s = new Scenario();
        $html = (string) $s->loggedIn($s->owner)->get('/quests/impact.did-it-work')->getBody();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        self::assertStringContainsString('You changed a title tag last week', $html);
        self::assertStringContainsString('Telling real impact from ranking noise', $html);
        self::assertStringContainsString('Calling a result after 3 days', $html);
        self::assertSame('/quests/guide/19', $doc->querySelector('a[href^="/quests/guide/"]')?->getAttribute('href'));
        $show = $doc->querySelector('a[data-show-me]');
        self::assertSame('/projects/' . $s->acmeProject->publicId . '/annotations', $show?->getAttribute('href'));
        self::assertSame('#annotation-form', $show->getAttribute('data-show-me'));
        self::assertNotNull($doc->querySelector('.quest-steps li[aria-current="step"]'));
        self::assertSame('/quests/impact.did-it-work/skip', $doc->querySelector('form[action$="/skip"]')?->getAttribute('action'));
        self::assertSame(404, $s->loggedIn($s->owner)->get('/quests/impact.not-a-quest')->getStatusCode());
    }

    public function testGuideChapterRendersInsideSeocc(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $html = (string) $browser->get('/quests/guide/26')->getBody();
        self::assertStringContainsString('The Quest Log', $html);
        self::assertStringContainsString('<a href="/quests/guide/04">chapter 4</a>', $html);
        self::assertSame(404, $browser->get('/quests/guide/99')->getStatusCode());
    }

    public function testSkipAndUnskipNeedCsrfAndFoundationsCannotBeSkipped(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/quests');
        self::assertSame(419, $browser->post('/quests/impact.did-it-work/skip', [], withCsrf: false)->getStatusCode());
        $browser->get('/quests');
        self::assertSame(303, $browser->post('/quests/impact.did-it-work/skip')->getStatusCode());
        $progress = $s->container->make(InMemoryQuestProgress::class);
        self::assertNotNull($progress->progress($s->owner->id, $s->acme->id)['impact.did-it-work']['']['skipped']);
        self::assertStringContainsString('Bring this quest back', (string) $browser->get('/quests/impact.did-it-work')->getBody());
        self::assertSame(303, $browser->post('/quests/impact.did-it-work/unskip')->getStatusCode());
        $browser->get('/quests');
        self::assertNull($progress->progress($s->owner->id, $s->acme->id)['impact.did-it-work']['']['skipped']);
        foreach (['foundations.add-website', QuestCatalogue::WEEKLY, 'impact.unknown'] as $key) {
            $browser->get('/quests');
            self::assertSame(404, $browser->post('/quests/' . $key . '/skip')->getStatusCode(), $key);
        }
        // Skipping is a user-level preference, not an audited action.
        self::assertSame([], array_filter($s->audit()->events, static fn($e): bool => str_starts_with($e->action, 'quest')));
    }

    public function testWidgetReplacesTheSetupBannerAndCanBeHiddenServerSide(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $dashboard = HTMLDocument::createFromString((string) $browser->get('/')->getBody(), LIBXML_NOERROR);
        $widget = $dashboard->querySelector('.quest-widget');
        self::assertNotNull($widget);
        self::assertStringContainsString('Let’s get your website tracking', $widget->textContent ?? '');
        self::assertSame('/quests?chapter=foundations&project=' . $s->acmeProject->publicId, $widget->querySelector('a.button')?->getAttribute('href'));
        self::assertNull($dashboard->querySelector('[data-setup-banner]'));
        $overview = HTMLDocument::createFromString((string) $browser->get('/projects/' . $s->acmeOther->publicId)->getBody(), LIBXML_NOERROR);
        self::assertStringContainsString('for Acme Blog', $overview->querySelector('.quest-widget')->textContent ?? '');

        self::assertSame(419, $browser->post('/quests/visibility', ['hidden' => '1'], withCsrf: false)->getStatusCode());
        $browser->get('/');
        $hide = $browser->post('/quests/visibility', ['hidden' => '1', 'return' => '/']);
        self::assertSame(303, $hide->getStatusCode());
        self::assertSame('/', $hide->getHeaderLine('Location'));
        self::assertTrue($s->container->make(InMemoryQuestProgress::class)->profile($s->owner->id, $s->acme->id)->hidden);
        self::assertStringNotContainsString('quest-widget', (string) $browser->get('/')->getBody());
        self::assertStringNotContainsString('quest-widget', (string) $browser->get('/projects/' . $s->acmeProject->publicId)->getBody());
        // The Quest Log stays reachable and offers to show the card again.
        self::assertStringContainsString('Show it again', (string) $browser->get('/quests')->getBody());
        // Only local paths are accepted as the return target.
        $browser->get('/quests');
        self::assertSame('/quests', $browser->post('/quests/visibility', ['hidden' => '0', 'return' => '//evil.example/'])->getHeaderLine('Location'));
        self::assertStringContainsString('quest-widget', (string) $browser->get('/')->getBody());
    }

    public function testWidgetFollowsTheCurrentQuestAndShowsTheAttentionMarker(): void
    {
        $s = new Scenario();
        $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo::class)->saveBudget($s->acme->id, 'dataforseo', new \SeoControlCenter\Domain\Integrations\ApiBudget(monthlyLimit: 0), $s->owner->id);
        $html = (string) $s->loggedIn($s->owner)->get('/')->getBody();
        $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        self::assertStringContainsString('Needs attention', $doc->querySelector('.quest-widget')->textContent ?? '');
    }

    public function testGetStartedRedirectsToFoundationsWithTheSameProject(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $plain = $browser->get('/get-started');
        self::assertSame(302, $plain->getStatusCode());
        self::assertSame('/quests?chapter=foundations', $plain->getHeaderLine('Location'));
        $withProject = $browser->get('/get-started?project=' . $s->acmeOther->publicId);
        self::assertSame(302, $withProject->getStatusCode());
        self::assertSame('/quests?chapter=foundations&project=' . $s->acmeOther->publicId, $withProject->getHeaderLine('Location'));
        $doc = HTMLDocument::createFromString((string) $browser->get($withProject->getHeaderLine('Location'))->getBody(), LIBXML_NOERROR);
        self::assertSame($s->acmeOther->publicId, $doc->querySelector('#setup-project option[selected]')?->getAttribute('value'));
    }

    public function testEntryPointsLinkToTheQuestLog(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $doc = HTMLDocument::createFromString((string) $browser->get('/projects/' . $s->acmeProject->publicId)->getBody(), LIBXML_NOERROR);
        self::assertSame('Quest Log', trim($doc->querySelector('nav a[href="/quests"]')->textContent ?? ''));
        self::assertNull($doc->querySelector('a[href^="/get-started"]'));
        $setup = null;
        foreach ($doc->querySelectorAll('.project-header a') as $link) {
            if (trim($link->textContent ?? '') === 'Setup guide') {
                $setup = $link->getAttribute('href');
            }
        }
        self::assertSame('/quests?chapter=foundations&project=' . $s->acmeProject->publicId, $setup);
    }

    public function testShowMeTargetsResolveToPagesWithTheSpotlightElement(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $targets = $s->container->make(ShowMeTargets::class);
        $checked = 0;
        foreach (QuestCatalogue::quests() as $quest) {
            foreach ($quest->steps as $step) {
                if ($step->showMe === null) {
                    continue;
                }
                $url = $targets->url($step->showMe, $s->acmeProject);
                self::assertNotNull($url, $quest->key . '/' . $step->key);
                if ($step->showMe->route === 'page_monitoring.index') {
                    // Page monitoring has no in-memory repository; its page needs the database.
                    $source = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/page-monitoring/index.php');
                    if ($step->showMe->selector !== null) {
                        self::assertStringContainsString('id="' . ltrim($step->showMe->selector, '#') . '"', $source);
                    }
                    $checked++;
                    continue;
                }
                $response = $browser->get($url);
                self::assertSame(200, $response->getStatusCode(), $quest->key . ' → ' . $url);
                if ($step->showMe->selector !== null) {
                    $doc = HTMLDocument::createFromString((string) $response->getBody(), LIBXML_NOERROR);
                    self::assertNotNull($doc->querySelector($step->showMe->selector), $quest->key . ' → ' . $url . ' ' . $step->showMe->selector);
                }
                $checked++;
            }
        }
        self::assertGreaterThan(30, $checked);
    }

    public function testAskAiAppearsOnlyWhenTheAssistantIsAvailable(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        self::assertStringNotContainsString('data-assistant-ask', (string) $browser->get('/quests/impact.did-it-work')->getBody());
        self::assertNull($this->quest($browser->get('/quests')->getBody(), 'Ask the assistant'));
        $s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryAssistant::class)->save($s->acme->id, 'anthropic', 'sk-ant-test', 'claude-sonnet-5-5', $s->owner->id);
        $html = (string) $browser->get('/quests/impact.did-it-work')->getBody();
        self::assertStringContainsString('Ask AI about this quest', $html);
        self::assertStringContainsString('The current step is &quot;Record a change on the Change Timeline', $html);
        self::assertSame(200, $browser->get('/quests/action.ask-the-assistant')->getStatusCode());
    }

    public function testViewingNeverQueuesWorkOrCallsProviders(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        foreach (['/', '/quests', '/quests?chapter=mastery', '/quests/mastery.backlinks', '/quests/foundations.rank-tracking', '/projects/' . $s->acmeProject->publicId] as $page) {
            self::assertSame(200, $browser->get($page)->getStatusCode(), $page);
        }
        self::assertSame([], $s->container->make(InMemoryKeywords::class)->requests);
        self::assertSame([], $s->container->make(InMemoryRankings::class)->requests);
        self::assertSame([], $s->container->make(InMemorySiteAudit::class)->queuedCrawls);
        self::assertSame([], $s->container->make(InMemorySiteAudit::class)->queuedRenders);
        self::assertSame([], $s->container->make(InMemoryReports::class)->queued);
        self::assertSame([], $s->container->make(FakeHttpClient::class)->requests);
    }

    public function testCompletedLearningQuestShowsAndEarnsTheRankToast(): void
    {
        $s = new Scenario();
        $evidence = $s->container->make(InMemoryQuestEvidence::class);
        foreach (['opportunities.business-profile', 'opportunities.keyword-rated', 'opportunities.analyzed', 'opportunities.acted', 'opportunities.serps', 'competitors.added', 'competitors.positions'] as $signal) {
            $evidence->grant($signal, $s->owner->id, $s->acme->id);
        }
        $browser = $s->loggedIn($s->owner);
        $html = (string) $browser->get('/quests?chapter=wins')->getBody();
        self::assertStringContainsString('Rank earned: <strong>Strategist</strong>', $html);
        self::assertStringNotContainsString('Rank earned: <strong>Strategist</strong>', (string) $browser->get('/quests?chapter=wins')->getBody());
        self::assertStringContainsString('Completed', $this->quest($html, 'Find your next move')->textContent ?? '');
    }

    public function testNoticesAreOnlyConsumedWhereTheyAreShown(): void
    {
        $s = new Scenario();
        $features = $s->container->make(FixedFeatures::class);
        $features->off = FixedFeatures::allExcept([]);
        $browser = $s->loggedIn($s->owner);
        $browser->get('/quests');
        // Opportunities is turned on: the dashboard card names the new quests.
        $features->off = FixedFeatures::allExcept(['opportunities']);
        self::assertStringContainsString('Find your next move', HTMLDocument::createFromString((string) $browser->get('/')->getBody(), LIBXML_NOERROR)->querySelector('.quest-widget')->textContent ?? '');

        // Skipping the last open quest of a chapter earns the rank; the toast waits for the page the user lands on.
        $evidence = $s->container->make(InMemoryQuestEvidence::class);
        // With Opportunities only, chapter 3 has two quests: one done by evidence, one left to skip.
        foreach (['opportunities.business-profile', 'opportunities.keyword-rated'] as $signal) {
            $evidence->grant($signal, $s->owner->id, $s->acme->id);
        }
        $browser->get('/quests/wins.next-move');
        $progress = $s->container->make(InMemoryQuestProgress::class);
        self::assertArrayNotHasKey(3, $progress->ranks($s->owner->id, $s->acme->id));
        $browser->post('/quests/wins.next-move/skip');
        $landing = (string) $browser->get('/quests/wins.next-move')->getBody();
        self::assertStringContainsString('Rank earned: <strong>Strategist</strong>', $landing);
        self::assertTrue($progress->ranks($s->owner->id, $s->acme->id)[3]['announced']);
        $browser->get('/quests');

        // A hidden card does no work, so notices stay for the Quest Log.
        $browser->post('/quests/visibility', ['hidden' => '1']);
        $features->off = [];
        self::assertStringNotContainsString('quest-widget', (string) $browser->get('/')->getBody());
        self::assertStringContainsString('New quests available', (string) $browser->get('/quests')->getBody());
    }

    private function quest(mixed $html, string $title): ?\Dom\Element
    {
        $doc = HTMLDocument::createFromString((string) $html, LIBXML_NOERROR);
        foreach ($doc->querySelectorAll('.quest-list > li') as $item) {
            if (trim($item->querySelector('h3')->textContent ?? '') === $title) {
                return $item;
            }
        }

        return null;
    }
}

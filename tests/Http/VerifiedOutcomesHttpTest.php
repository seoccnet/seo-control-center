<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\OpportunityOutcome;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Tests\Support\Fakes\FakePageChecks;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOutcomes;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPageWatches;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * ADR-039, E2: bulk page watches with before/after checks, the workspace
 * watch limit and confirming whether a completed opportunity was done.
 */
final class VerifiedOutcomesHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testBulkAddReadsEachNewPageAndRespectsTheLimit(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $page = $this->monitoring();
        self::assertStringContainsString('0 / 50', (string) $owner->get($page)->getBody());
        $response = $owner->post($page . '/bulk', ['urls' => "https://shop.acme.test/a\nhttps://shop.acme.test/b\nhttps://shop.acme.test/a\nhttps://rival.test/x", 'pick' => ['https://shop.acme.test/c']]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['https://shop.acme.test/a', 'https://shop.acme.test/b', 'https://shop.acme.test/c'], array_column($this->watches()->watches[$this->s->acmeProject->id], 'url'));
        self::assertSame(['https://shop.acme.test/a', 'https://shop.acme.test/b', 'https://shop.acme.test/c'], array_column($this->checks()->checks, 'url'), 'Each new watch gets its before snapshot now.');
        $html = (string) $owner->get($page)->getBody();
        self::assertStringContainsString('3 pages added', $html);
        self::assertStringContainsString('Not a page on this project’s domain: https://rival.test/x', $html);
        self::assertContains('page_monitoring.watched_bulk', $this->s->audit()->actions());

        // Workspace managers lower the limit; further pages are refused, existing ones stay.
        self::assertSame(303, $owner->post($page . '/limit', ['limit' => '4'])->getStatusCode());
        $owner->post($page . '/bulk', ['urls' => "https://shop.acme.test/a\nhttps://shop.acme.test/d\nhttps://shop.acme.test/e"]);
        $html = (string) $owner->get($page)->getBody();
        self::assertStringContainsString('1 page added', $html);
        self::assertStringContainsString('1 already watched', $html);
        self::assertStringContainsString('1 not added: the limit is 4 pages per project', $html);
        self::assertStringContainsString('4 / 4', $html);
        self::assertStringContainsString('already watches 4 pages, the limit of this workspace. Stop watching a page below or raise the limit', $html, 'A full list says so instead of offering pages.');
        self::assertStringNotContainsString('Watch these pages', $html);
        $owner->post($page . '/limit', ['limit' => '500']);
        self::assertStringContainsString('between 1 and 200', (string) $owner->get($page)->getBody());
        $owner->post($page . '/bulk', ['urls' => '']);
        self::assertStringContainsString('Paste between 1 and 200 page URLs', (string) $owner->get($page)->getBody());
    }

    public function testOnlyEditorsAddAndOnlyManagersSetTheLimit(): void
    {
        $page = $this->monitoring();
        $member = $this->s->loggedIn($this->s->member);
        $member->get($page);
        self::assertSame(303, $member->post($page . '/bulk', ['urls' => 'https://shop.acme.test/a'])->getStatusCode());
        self::assertSame(403, $member->post($page . '/limit', ['limit' => '20'])->getStatusCode());
        self::assertStringNotContainsString('name="limit"', (string) $member->get($page)->getBody());
        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewer->get($page);
        self::assertSame(403, $viewer->post($page . '/bulk', ['urls' => 'https://shop.acme.test/b'])->getStatusCode());
        self::assertCount(1, $this->watches()->watches[$this->s->acmeProject->id]);
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/projects/' . $this->s->rivalProject->publicId . '/page-monitoring');
        self::assertSame(404, $rival->post($page . '/bulk', ['urls' => 'https://shop.acme.test/c'])->getStatusCode());
        $rival->get('/projects/' . $this->s->rivalProject->publicId . '/page-monitoring');
        self::assertSame(404, $rival->post($page . '/limit', ['limit' => '20'])->getStatusCode());
        self::assertSame(50, $this->watches()->limit($this->s->acme->id));
    }

    public function testAChangeNoteForAPageSchedulesBeforeAndAfterChecks(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $notes = '/projects/' . $this->s->acmeProject->publicId . '/annotations';
        $owner->get($notes);
        self::assertSame(303, $owner->post($notes, ['date' => date('Y-m-d'), 'kind' => 'content', 'title' => 'New title', 'url' => 'https://shop.acme.test/shoes'])->getStatusCode());
        $watch = $this->watches()->watched($this->s->acmeProject, 'https://shop.acme.test/shoes');
        self::assertNotNull($watch, 'The noted page is watched.');
        self::assertSame('change_note', $watch['reason']);
        self::assertNotNull($watch['after_due_at']);
        self::assertSame(['https://shop.acme.test/shoes'], array_column($this->checks()->checks, 'url'));

        $owner->post($notes, ['date' => date('Y-m-d'), 'kind' => 'content', 'title' => 'Site-wide theme']);
        $owner->post($notes, ['date' => date('Y-m-d'), 'kind' => 'content', 'title' => 'Elsewhere', 'url' => 'https://rival.test/x']);
        self::assertCount(1, $this->watches()->watches[$this->s->acmeProject->id], 'Site-wide notes and foreign URLs are not watched.');
    }

    public function testConfirmingAnOutcomeIsAuditedAndScoped(): void
    {
        $outcomes = $this->s->container->get(InMemoryOutcomes::class);
        self::assertInstanceOf(InMemoryOutcomes::class, $outcomes);
        $row = new StoredOpportunity('0190a8f0-0000-7000-8000-000000000001', OpportunityType::QuickWin, 'Improve shoes', 'Review', [['label' => 'Page', 'value' => 'https://shop.acme.test/shoes']], 50, 80, 1, 40, 'done', '2026-10-01', '2026-10-08', 150);
        $outcomes->record($this->s->acmeProject->id, $this->s->acme->id, $row, date('Y-m-d'), null, null, 'finding', 'https://shop.acme.test/shoes');
        $id = $outcomes->recent($this->s->acmeProject->id)[0]->publicId;
        $base = '/projects/' . $this->s->acmeProject->publicId . '/opportunities/outcomes';

        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($base)->getBody();
        self::assertStringContainsString('Not verified', $html);
        self::assertSame(1, substr_count($html, 'Confirm done'), 'Once per completion, on the day-28 row.');
        self::assertSame(303, $owner->post($base . '/' . $id . '/implementation', ['implemented' => '0'])->getStatusCode());
        foreach ($outcomes->recent($this->s->acmeProject->id) as $outcome) {
            self::assertSame(OpportunityOutcome::NOT_IMPLEMENTED, $outcome->implementation, 'Both checkpoints change together.');
            self::assertSame('user', $outcome->implementationSource);
        }
        $html = (string) $owner->get($base)->getBody();
        self::assertStringContainsString('no longer counts toward the estimate calibration', $html);
        self::assertStringContainsString('Not done', $html);
        self::assertContains('opportunities.outcome_confirmed', $this->s->audit()->actions());

        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringNotContainsString('Confirm done', (string) $viewer->get($base)->getBody());
        self::assertSame(403, $viewer->post($base . '/' . $id . '/implementation', ['implemented' => '1'])->getStatusCode());
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/projects/' . $this->s->rivalProject->publicId . '/page-monitoring');
        self::assertSame(404, $rival->post('/projects/' . $this->s->rivalProject->publicId . '/opportunities/outcomes/' . $id . '/implementation', ['implemented' => '1'])->getStatusCode(), 'An outcome of another workspace is not found through the rival project.');
        $rival->get('/projects/' . $this->s->rivalProject->publicId . '/page-monitoring');
        self::assertSame(404, $rival->post($base . '/' . $id . '/implementation', ['implemented' => '1'])->getStatusCode());
        self::assertSame(OpportunityOutcome::NOT_IMPLEMENTED, $outcomes->recent($this->s->acmeProject->id)[0]->implementation);
    }

    private function monitoring(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/page-monitoring';
    }

    private function watches(): InMemoryPageWatches
    {
        $watches = $this->s->container->get(InMemoryPageWatches::class);
        self::assertInstanceOf(InMemoryPageWatches::class, $watches);

        return $watches;
    }

    private function checks(): FakePageChecks
    {
        $checks = $this->s->container->get(FakePageChecks::class);
        self::assertInstanceOf(FakePageChecks::class, $checks);

        return $checks;
    }
}

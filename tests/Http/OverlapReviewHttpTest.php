<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ContentInventory\OverlapFinder;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Fakes\InMemoryArticleIdeas;
use SeoControlCenter\Tests\Support\Fakes\InMemoryContentItems;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOverlapReviews;
use SeoControlCenter\Tests\Support\Scenario;

/** Research → Content inventory → Overlap review (ADR-042): evidence first, people decide, nothing merges itself. */
final class OverlapReviewHttpTest extends TestCase
{
    private const string TEXT = 'Mold on pepper starts in dense blossoms when humidity stays high for days. Check the inside of clusters for grey fuzz, cut affected blossoms well below the rot and lower the humidity in the grow room to stop it spreading to other plants before harvest.';

    private Scenario $s;

    private string $base;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->base = '/projects/' . $this->s->acmeProject->publicId . '/inventory/overlap';
        $this->s->container->make(InMemoryOverlapReviews::class)->pages[$this->s->acmeProject->id] = [
            ['url' => 'https://shop.acme.test/blog/mold-on-pepper', 'title' => 'Mold on pepper', 'text' => self::TEXT],
            ['url' => 'https://shop.acme.test/blog/blossom-rot', 'title' => 'Blossom rot', 'text' => self::TEXT . ' Most growers notice it too late.'],
        ];
        $this->s->container->make(InMemoryContentItems::class)->records[$this->s->acmeProject->id] = [
            ['source' => 'search_console', 'url' => 'https://shop.acme.test/blog/mold-on-pepper', 'ref' => 'a', 'title' => null, 'status' => 'impressions', 'observed_at' => '2026-10-20', 'detail' => '900 impressions, 30 clicks in 28 days', 'impressions' => 900, 'clicks' => 30],
            ['source' => 'search_console', 'url' => 'https://shop.acme.test/blog/blossom-rot', 'ref' => 'b', 'title' => null, 'status' => 'impressions', 'observed_at' => '2026-10-20', 'detail' => '200 impressions, 4 clicks in 28 days', 'impressions' => 200, 'clicks' => 4],
        ];
        $this->s->container->make(InMemoryArticleIdeas::class)->replaceIdeas($this->s->acmeProject->id, $this->s->acme->id, 1, [self::idea('mold on pepper'), self::idea('pepper mold')], '2026-10-20 10:00:00');
    }

    public function testNearDuplicatesReachTheOpportunitySignalsUntilKeptSeparate(): void
    {
        $collector = $this->s->container->make(\SeoControlCenter\Modules\Opportunities\SignalCollector::class);
        $signals = $collector->collect($this->s->acmeProject->id, $this->s->acme->id);
        $key = OverlapFinder::key(OverlapFinder::DUPLICATE, ['https://shop.acme.test/blog/mold-on-pepper', 'https://shop.acme.test/blog/blossom-rot']);
        self::assertSame('available', $signals->coverage['near_duplicates']['status'] ?? null);
        self::assertSame([$key], array_column($signals->nearDuplicates, 'key'));
        self::assertSame([], $collector->collect($this->s->rivalProject->id, $this->s->rival->id)->nearDuplicates, 'another project has its own pages');

        $owner = $this->s->loggedIn($this->s->owner);
        self::assertSame(303, $this->post($owner, $this->base . '/' . $key, ['decision' => 'keep_separate', 'version' => '0'])->getStatusCode());
        self::assertSame([], $collector->collect($this->s->acmeProject->id, $this->s->acme->id)->nearDuplicates, 'a pair a person kept separate is not proposed for merging');
    }

    public function testProductPagesSharingADescriptionAreNeverProposedForMerging(): void
    {
        // Finding 39: one cultivar from two breeders shares its description; they are separate products.
        $overlaps = $this->s->container->make(InMemoryOverlapReviews::class);
        $overlaps->products[$this->s->acmeProject->id] = ['https://shop.acme.test/blog/blossom-rot'];
        $collector = $this->s->container->make(\SeoControlCenter\Modules\Opportunities\SignalCollector::class);
        self::assertSame([], $collector->collect($this->s->acmeProject->id, $this->s->acme->id)->nearDuplicates);
        self::assertStringContainsString('Near-duplicate text', (string) $this->s->loggedIn($this->s->owner)->get($this->base)->getBody(), 'the overlap review still lists the pair for a person to decide');
    }

    public function testCandidatesShowEvidenceAndAPreviewButNothingIsMerged(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('Near-duplicate text', $html);
        self::assertStringContainsString('Article ideas on the same subject', $html, 'Two near-synonym ideas are proposed for review.');
        self::assertStringContainsString('Proposed address to keep: <strong>/blog/mold-on-pepper</strong>. It has the most Google clicks in the last 28 days.', $html);
        self::assertStringContainsString('SEOcc changes nothing on your site', $html);
        self::assertStringContainsString('2 of the 2 listed not decided yet.', $html);
        self::assertCount(2, $this->s->container->make(InMemoryArticleIdeas::class)->ideas($this->s->acmeProject, 10), 'Both ideas stay: nothing is merged automatically.');
    }

    public function testPeopleDecideAndAPlannedMergeNamesWhatToKeep(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $key = OverlapFinder::key(OverlapFinder::DUPLICATE, ['https://shop.acme.test/blog/mold-on-pepper', 'https://shop.acme.test/blog/blossom-rot']);
        self::assertSame(422, $this->post($owner, $this->base . '/' . $key, ['decision' => 'merge_planned', 'retained' => '', 'version' => '0'])->getStatusCode(), 'A merge must say which page stays.');
        self::assertSame(422, $this->post($owner, $this->base . '/' . $key, ['decision' => 'merge_planned', 'retained' => 'https://shop.acme.test/elsewhere', 'version' => '0'])->getStatusCode());
        self::assertSame(303, $this->post($owner, $this->base . '/' . $key, ['decision' => 'merge_planned', 'retained' => 'https://shop.acme.test/blog/mold-on-pepper', 'note' => 'Redirect blossom rot in the CMS', 'version' => '0'])->getStatusCode());
        self::assertSame(409, $this->post($owner, $this->base . '/' . $key, ['decision' => 'keep_separate', 'version' => '0'])->getStatusCode(), 'A decision from an old version is refused.');
        self::assertSame(404, $this->post($owner, $this->base . '/' . str_repeat('f', 64), ['decision' => 'keep_separate', 'version' => '0'])->getStatusCode(), 'Only current candidates can be decided.');

        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('Merge planned', $html);
        self::assertStringContainsString('Approved plan: keep <strong>/blog/mold-on-pepper</strong>, carried out in your CMS.', $html);
        self::assertStringContainsString('1 of the 2 listed not decided yet.', $html);
        self::assertContains('inventory.overlap_decided', $this->s->audit()->actions());

        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringNotContainsString('Save decision', (string) $viewer->get($this->base)->getBody());
        self::assertSame(403, $this->post($viewer, $this->base . '/' . $key, ['decision' => 'keep_separate', 'version' => '1'])->getStatusCode());
    }

    /** @param array<string, string> $data */
    private function post(Browser $browser, string $path, array $data): ResponseInterface
    {
        $browser->get('/account');

        return $browser->post($path, $data);
    }

    private static function idea(string $main): ArticleIdea
    {
        return new ArticleIdea(hash('sha256', $main), ArticleIdea::NEW, $main, 1000, [['keyword' => $main, 'volume' => 1000, 'difficulty' => 10]], [], null, [
            'traffic' => ['low' => 10, 'expected' => 40, 'high' => 90],
            'difficulty' => ['level' => 'low', 'reasons' => []],
            'backlinks' => null,
            'months' => ['low' => 1, 'high' => 3, 'basis' => 'An assumption.'],
        ], 1.0, []);
    }
}

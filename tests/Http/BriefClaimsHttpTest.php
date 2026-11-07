<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SeoControlCenter\Modules\ContentBriefs\BriefInput;
use SeoControlCenter\Modules\ContentBriefs\BriefRepository;
use SeoControlCenter\Tests\Support\Browser;
use SeoControlCenter\Tests\Support\Fakes\InMemoryClaims;
use SeoControlCenter\Tests\Support\Scenario;

/** Claims and sources on content briefs (ADR-042): verdicts by people, flags by SEOcc, every change kept. */
final class BriefClaimsHttpTest extends TestCase
{
    private Scenario $s;

    private string $base;

    private string $briefId;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $project = $this->s->acmeProject;
        $brief = $this->s->container->make(BriefRepository::class)->create($project, BriefInput::fromArray(['title' => 'Brandywine guide', 'keyword' => 'brandywine cultivar', 'status' => 'draft'], $project), [], $this->s->owner->id);
        $this->briefId = $brief->publicId;
        $this->base = '/projects/' . $project->publicId . '/content-briefs/' . $brief->publicId;
    }

    public function testASourceAboutAnotherBreedersProductIsFlaggedWithoutChangingTheVerdict(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertSame(303, $this->post($owner, $this->base . '/claims', [
            'claim' => 'Brandywine from Meadow Seeds reaches 25% NPK.', 'subject' => 'Brandywine seeds page',
            'claim_breeder' => 'Meadow Seeds', 'claim_cultivar' => 'Brandywine',
            'source_url' => 'https://breeder.test/brandywine-33', 'publisher' => 'Hillside Farm', 'checked_on' => '2026-10-20',
            'passage' => 'Brandywine #33: NPK up to 25%', 'source_breeder' => 'Hillside Farm', 'source_cultivar' => 'Brandywine #33',
            'status' => 'supported',
        ])->getStatusCode());
        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('Claims and sources', $html);
        self::assertStringContainsString('The source is about breeder or brand &quot;Hillside Farm&quot;, the claim is about &quot;Meadow Seeds&quot;', $html);
        self::assertStringContainsString('(not a verdict)', $html);
        $claim = $this->claims()->forBrief($this->s->acmeProject->id, $this->briefId)[0];
        self::assertSame('supported', $claim->status, 'The person\'s verdict stays; the flag sits next to it.');
        self::assertContains('briefs.claim_added', $this->s->audit()->actions());
    }

    public function testChangedEvidenceResetsAnOldVerdictAndEveryVersionIsKept(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $this->post($owner, $this->base . '/claims', ['claim' => 'Brandywine flowers in 8 weeks.', 'source_url' => 'https://lab.test/a', 'passage' => 'Flowering: 56 days', 'status' => 'supported']);
        $claim = $this->claims()->forBrief($this->s->acmeProject->id, $this->briefId)[0];

        self::assertSame(303, $this->post($owner, $this->base . '/claims/' . $claim->publicId, ['claim' => 'Brandywine flowers in 8 weeks.', 'source_url' => 'https://lab.test/b', 'passage' => 'Flowering: 56 days', 'status' => 'supported', 'version' => '1'])->getStatusCode());
        $saved = $this->claims()->find($this->s->acmeProject->id, $claim->publicId);
        self::assertSame(['unreviewed', 2], [$saved?->status, $saved?->version], 'A verdict given for another source does not carry over.');

        $this->post($owner, $this->base . '/claims/' . $claim->publicId, ['claim' => 'Brandywine flowers in 8 weeks.', 'source_url' => 'https://lab.test/b', 'passage' => 'Flowering: 56 days', 'status' => 'partly_supported', 'note' => 'Indoor only', 'version' => '2']);
        self::assertSame('partly_supported', $this->claims()->find($this->s->acmeProject->id, $claim->publicId)?->status);
        self::assertSame(409, $this->post($owner, $this->base . '/claims/' . $claim->publicId, ['claim' => 'Stale', 'status' => 'supported', 'version' => '1'])->getStatusCode(), 'An edit of an old version is refused.');

        $html = (string) $owner->get($this->base)->getBody();
        self::assertStringContainsString('History (2 earlier versions)', $html);
        self::assertStringContainsString('https://lab.test/a', $html, 'The first source stays in the history.');
        self::assertSame(422, $this->post($owner, $this->base . '/claims', ['claim' => '', 'status' => 'supported'])->getStatusCode());
        self::assertSame(422, $this->post($owner, $this->base . '/claims', ['claim' => 'x', 'status' => 'proven'])->getStatusCode());
        self::assertSame(422, $this->post($owner, $this->base . '/claims', ['claim' => 'x', 'checked_on' => '2099-01-01'])->getStatusCode());
    }

    public function testViewersReadClaimsButOnlyEditorsChangeThem(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $this->post($owner, $this->base . '/claims', ['claim' => 'Brandywine is a hybrid.', 'status' => 'unverifiable']);
        $viewer = $this->s->loggedIn($this->s->viewer);
        $html = (string) $viewer->get($this->base)->getBody();
        self::assertStringContainsString('Brandywine is a hybrid.', $html);
        self::assertStringNotContainsString('Add a claim', $html);
        $claim = $this->claims()->forBrief($this->s->acmeProject->id, $this->briefId)[0];
        self::assertSame(403, $this->post($viewer, $this->base . '/claims', ['claim' => 'X', 'status' => 'supported'])->getStatusCode());
        self::assertSame(403, $this->post($viewer, $this->base . '/claims/' . $claim->publicId, ['claim' => 'X', 'status' => 'supported', 'version' => '1'])->getStatusCode());
        self::assertSame(403, $this->post($viewer, $this->base . '/claims/' . $claim->publicId . '/delete')->getStatusCode());
        self::assertSame(303, $this->post($owner, $this->base . '/claims/' . $claim->publicId . '/delete')->getStatusCode());
        self::assertSame([], $this->claims()->forBrief($this->s->acmeProject->id, $this->briefId));
    }

    /** @param array<string, string> $data */
    private function post(Browser $browser, string $path, array $data = []): ResponseInterface
    {
        $browser->get('/account');

        return $browser->post($path, $data);
    }

    private function claims(): InMemoryClaims
    {
        return $this->s->container->make(InMemoryClaims::class);
    }
}

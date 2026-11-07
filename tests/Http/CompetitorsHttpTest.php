<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Competitors page: adding (manual and suggested), backfill from stored
 * checks, comparison and gaps, permissions and tenant isolation.
 */
final class CompetitorsHttpTest extends TestCase
{
    private Scenario $s;

    private InMemoryCompetitors $competitors;

    private InMemoryKeywords $keywords;

    private InMemoryRankings $rankings;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $competitors = $this->s->container->get(InMemoryCompetitors::class);
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        self::assertInstanceOf(InMemoryCompetitors::class, $competitors);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        $this->competitors = $competitors;
        $this->keywords = $keywords;
        $this->rankings = $rankings;
    }

    public function testAddingValidatesDomains(): void
    {
        $browser = $this->s->loggedIn($this->s->member);
        $browser->get($this->url());

        self::assertSame(303, $browser->post($this->url(), ['domain' => 'https://www.Rival.test/shop'])->getStatusCode());
        self::assertSame(['rival.test'], array_map(static fn($c): string => $c->domain, $this->competitors->forProject($this->s->acmeProject->id)));
        self::assertContains('competitors.added', $this->s->audit()->actions());

        $duplicate = $browser->post($this->url(), ['domain' => 'rival.test']);
        self::assertSame(422, $duplicate->getStatusCode());
        self::assertStringContainsString('rival.test is already a competitor.', (string) $duplicate->getBody());
        self::assertStringContainsString('own domain', (string) $browser->post($this->url(), ['domain' => 'acme.test'])->getBody(), 'The project domain shop.acme.test belongs to acme.test.');
        self::assertStringContainsString('Enter a domain', (string) $browser->post($this->url(), ['domain' => 'not a domain'])->getBody());

        for ($i = 1; $i <= 9; ++$i) {
            $browser->post($this->url(), ['domain' => "rival{$i}.test"]);
        }
        $eleventh = $browser->post($this->url(), ['domain' => 'eleventh.test']);
        self::assertSame(422, $eleventh->getStatusCode());
        self::assertStringContainsString('maximum of 10 competitors', (string) $eleventh->getBody());
        self::assertCount(10, $this->competitors->forProject($this->s->acmeProject->id));
    }

    public function testSuggestedCompetitorIsBackfilledAndCompared(): void
    {
        $project = $this->s->acmeProject->id;
        $this->keywords->add($project, ['red shoes', 'blue shoes'], KeywordLocale::default(), $this->s->owner->id);
        $this->rankings->saveSettings($project, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::DAILY), $this->s->owner->id);
        [$red, $blue] = $this->keywords->keywords[$project];
        $top = static fn(array $domains): array => array_map(static fn(int $i, string $d): array => ['position' => $i + 1, 'domain' => $d, 'url' => 'https://' . $d . '/'], array_keys($domains), $domains);
        foreach (['2026-09-19', '2026-09-26'] as $day) {
            $this->rankings->storeSnapshot(new RankSnapshot($red['id'], $project, SerpDevice::Desktop, $day, 5, 5, null, [], $top(['www.rival.test', 'big.test', 'x.test', 'y.test', 'shop.acme.test'])));
            $this->rankings->storeSnapshot(new RankSnapshot($blue['id'], $project, SerpDevice::Desktop, $day, null, null, null, [], $top(['big.test', 'blog.rival.test'])));
            $this->rankings->aggregate($project, $this->s->acme->id, SerpDevice::Desktop, $day);
        }
        $browser = $this->s->loggedIn($this->s->owner);

        $html = (string) $browser->get($this->url())->getBody();
        self::assertStringContainsString('Suggested competitors', $html);
        self::assertMatchesRegularExpression('#<td>big\.test</td>\s*<td>2</td>#', $html, 'Most frequent first, www. dropped, own domain excluded.');
        self::assertStringNotContainsString('<td>shop.acme.test</td>', $html);

        self::assertSame(303, $browser->post($this->url(), ['domain' => 'rival.test', 'source' => 'discovered'])->getStatusCode());
        $rival = $this->competitors->forProject($project)[0];
        self::assertSame('discovered', $rival->source);

        $html = (string) $browser->get($this->url())->getBody();
        self::assertStringContainsString('>rival.test</a>', $html);
        self::assertStringNotContainsString('<td>rival.test</td>', $html, 'No longer suggested.');
        self::assertMatchesRegularExpression('#\?view=gaps">1</a>#', $html, 'blue shoes: rival.test 2nd, Acme not ranking.');

        $detail = (string) $browser->get($this->url() . '/' . $rival->publicId . '?view=gaps')->getBody();
        self::assertStringContainsString('blue shoes', $detail);
        self::assertStringNotContainsString('>red shoes</a>', $detail, 'Only gaps in this view.');
        self::assertStringContainsString('They are ahead (2)', $detail);
    }

    public function testViewersReadOnlyAndOtherTenantsNotFound(): void
    {
        $competitor = $this->competitors->add($this->s->acmeProject->id, 'rival.test', 'manual', $this->s->owner->id);
        self::assertNotNull($competitor);

        $viewer = $this->s->loggedIn($this->s->viewer);
        $html = (string) $viewer->get($this->url())->getBody();
        self::assertStringContainsString('rival.test', $html);
        self::assertStringNotContainsString('Add a competitor', $html);
        $viewer->get('/account/security');
        $csrf = $viewer->csrfToken();
        self::assertSame(403, $viewer->post($this->url(), ['domain' => 'x.test', '_csrf' => $csrf])->getStatusCode());
        self::assertSame(403, $viewer->post($this->url() . '/' . $competitor->publicId . '/delete', ['_csrf' => $csrf])->getStatusCode());

        foreach ([$this->s->rivalOwner, $this->s->unassigned] as $user) {
            $browser = $this->s->loggedIn($user);
            self::assertSame(404, $browser->get($this->url())->getStatusCode());
            self::assertSame(404, $browser->get($this->url() . '/' . $competitor->publicId)->getStatusCode());
        }
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        $rival->get('/account/security');
        self::assertSame(404, $rival->post('/projects/' . $this->s->rivalProject->publicId . '/competitors/' . $competitor->publicId . '/delete')->getStatusCode());
        self::assertCount(1, $this->competitors->forProject($this->s->acmeProject->id));

        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/' . $competitor->publicId . '/delete')->getStatusCode());
        self::assertSame([], $this->competitors->forProject($this->s->acmeProject->id));
        self::assertContains('competitors.removed', $this->s->audit()->actions());
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/competitors';
    }
}

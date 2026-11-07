<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Rankings page: opt-in settings, manual checks, dashboard and tenant isolation.
 */
final class RankingsHttpTest extends TestCase
{
    private Scenario $s;

    private InMemoryRankings $rankings;

    private InMemoryKeywords $keywords;

    private InMemoryDataForSeo $dfs;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $rankings = $this->s->container->get(InMemoryRankings::class);
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $dfs = $this->s->container->get(InMemoryDataForSeo::class);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryDataForSeo::class, $dfs);
        $this->rankings = $rankings;
        $this->keywords = $keywords;
        $this->dfs = $dfs;
        $this->rankings->projects[$this->s->acmeProject->id] = ['workspace_id' => $this->s->acme->id, 'domain' => 'shop.acme.test'];
    }

    public function testSwitchingTrackingOnQueuesTheFirstCheck(): void
    {
        $this->connectDataForSeo();
        $this->keywords->add($this->s->acmeProject->id, ['red shoes', 'blue shoes'], KeywordLocale::default(), $this->s->owner->id);
        $browser = $this->s->loggedIn($this->s->member);
        $html = (string) $browser->get($this->url())->getBody();
        self::assertStringContainsString('estimated at up to $0.04 per check', $html);

        $response = $browser->post($this->url() . '/settings', ['enabled' => '1', 'device' => 'mobile', 'frequency' => 'weekly']);

        self::assertSame(303, $response->getStatusCode());
        $settings = $this->rankings->settings($this->s->acmeProject->id);
        self::assertTrue($settings->enabled);
        self::assertSame(SerpDevice::Mobile, $settings->device);
        self::assertSame(RankingSettings::WEEKLY, $settings->frequency);
        self::assertCount(1, $this->rankings->requests);
        self::assertContains('rankings.settings_updated', $this->s->audit()->actions());

        // Saving again without changes does not queue another check; "Check now" does.
        $browser->get($this->url());
        $browser->post($this->url() . '/settings', ['enabled' => '1', 'device' => 'mobile', 'frequency' => 'daily']);
        self::assertCount(1, $this->rankings->requests);
        $browser->get($this->url());
        self::assertSame(303, $browser->post($this->url() . '/check')->getStatusCode());
        self::assertCount(2, $this->rankings->requests);
    }

    public function testTrackingNeedsDataForSeoAndValidInput(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get($this->url());

        $response = $browser->post($this->url() . '/settings', ['enabled' => '1', 'device' => 'tablet', 'frequency' => 'hourly']);

        self::assertSame(422, $response->getStatusCode());
        $html = (string) $response->getBody();
        self::assertStringContainsString('Connect DataForSEO under Integrations before switching rank tracking on.', $html);
        self::assertFalse($this->rankings->settings($this->s->acmeProject->id)->enabled);

        $browser->get($this->url());
        $browser->post($this->url() . '/check');
        self::assertStringContainsString('Switch rank tracking on first.', (string) $browser->get($this->url())->getBody());
        self::assertSame([], $this->rankings->requests);
    }

    public function testDashboardShowsVisibilityWinnersAndLosers(): void
    {
        $project = $this->s->acmeProject->id;
        $this->keywords->add($project, ['red shoes', 'blue shoes', 'green shoes'], KeywordLocale::default(), $this->s->owner->id);
        $this->rankings->saveSettings($project, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::DAILY), $this->s->owner->id);
        $ids = array_column($this->keywords->keywords[$project], 'id');
        foreach ([['2026-09-19', [8, 2, null]], ['2026-09-26', [3, 6, 15]]] as [$day, $positions]) {
            foreach ($positions as $i => $position) {
                $this->rankings->storeSnapshot(new RankSnapshot($ids[$i], $project, SerpDevice::Desktop, $day, $position, $position, $position !== null ? 'https://shop.acme.test/p' . $i : null, [], []));
            }
            $this->rankings->aggregate($project, $this->s->acme->id, SerpDevice::Desktop, $day);
        }

        $html = (string) $this->s->loggedIn($this->s->viewer)->get($this->url())->getBody();

        self::assertStringContainsString('Visibility', $html);
        self::assertStringContainsString('vs 2026-09-19', $html);
        self::assertMatchesRegularExpression('#Winners.*red shoes.*Losers.*blue shoes#s', $html);
        self::assertStringContainsString('green shoes', $html);
        self::assertStringContainsString('&gt;100', $html);
        self::assertStringContainsString('data-suffix=" %"', $html, 'Visibility chart with two days of data.');
        self::assertStringNotContainsString('Tracking settings', $html, 'Viewers cannot change settings.');
    }

    public function testViewersCannotSpendAndOtherTenantsGetNotFound(): void
    {
        $this->connectDataForSeo();
        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewer->get('/account/security');
        $csrf = $viewer->csrfToken();
        self::assertSame(403, $viewer->post($this->url() . '/settings', ['enabled' => '1', 'device' => 'desktop', 'frequency' => 'daily', '_csrf' => $csrf])->getStatusCode());
        self::assertSame(403, $viewer->post($this->url() . '/check', ['_csrf' => $csrf])->getStatusCode());

        foreach ([$this->s->rivalOwner, $this->s->unassigned] as $user) {
            $browser = $this->s->loggedIn($user);
            self::assertSame(404, $browser->get($this->url())->getStatusCode());
            $browser->get('/account/security');
            $csrf = $browser->csrfToken();
            self::assertSame(404, $browser->post($this->url() . '/settings', ['enabled' => '1', 'device' => 'desktop', 'frequency' => 'daily', '_csrf' => $csrf])->getStatusCode());
            self::assertSame(404, $browser->post($this->url() . '/check', ['_csrf' => $csrf])->getStatusCode());
        }
        self::assertFalse($this->rankings->settings($this->s->acmeProject->id)->enabled);
        self::assertSame([], $this->rankings->requests);
    }

    private function connectDataForSeo(): void
    {
        $this->dfs->save($this->s->acme->id, new DataForSeoCredentials('api@acme.test', 'x'), 5.0, $this->s->owner->id);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/rankings';
    }
}

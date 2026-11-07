<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Modules\Local\LocalService;
use SeoControlCenter\Tests\Support\Fakes\FakeLocalProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLocal;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Local SEO: settings, keywords, checks against simulated Google Maps
 * results, the Business Profile, weekly checks, permissions and isolation.
 */
final class LocalHttpTest extends TestCase
{
    private Scenario $s;

    private FrozenClock $clock;

    private InMemoryLocal $local;

    private FakeLocalProvider $provider;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-10-03 08:00:00', new \DateTimeZone('UTC')));
        $this->s->container->instance(Clock::class, $this->clock);
        $local = $this->s->container->get(InMemoryLocal::class);
        $provider = $this->s->container->get(FakeLocalProvider::class);
        self::assertInstanceOf(InMemoryLocal::class, $local);
        self::assertInstanceOf(FakeLocalProvider::class, $provider);
        $this->local = $local;
        $this->provider = $provider;
        $this->provider->maps = [
            'seed shop' => [new LocalResult(1, 'Rival Garden', 'rival.test', rating: 4.9, votes: 300), new LocalResult(2, 'Acme Shop', 'shop.acme.test', rating: 4.6, votes: 80, cid: '777')],
            'garden center' => [new LocalResult(1, 'Rival Garden', 'rival.test', rating: 4.9, votes: 300), new LocalResult(2, 'Green World', null, rating: 4.1, votes: 12)],
        ];
        $this->provider->profile = new BusinessProfile('Acme Shop', 'Garden center', 4.6, 80, false, 'Main St 1', null, 'https://shop.acme.test/', 12, '777');
    }

    public function testSetupCheckAndResults(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringContainsString('not set up yet', (string) $owner->get($this->url())->getBody());

        $invalid = $owner->post($this->url() . '/settings', ['business_name' => '', 'coordinates' => 'Springfield', 'zoom' => '13', 'language' => 'en']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Enter the coordinates as latitude, longitude', (string) $invalid->getBody());
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/settings', ['business_name' => 'Acme Shop', 'coordinates' => '43.8563, 18.4131', 'zoom' => '14', 'language' => 'bs', 'weekly' => '1'])->getStatusCode());
        $settings = $this->local->settings($this->s->acmeProject->id);
        self::assertSame(['Acme Shop', '43.8563,18.4131,14z', 'bs', true], [$settings?->businessName, $settings?->area->coordinate(), $settings?->language, $settings?->weekly]);

        $owner->get($this->url());
        self::assertSame(409, $owner->post($this->url() . '/check')->getStatusCode(), 'keywords first');
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/keywords', ['keywords' => "Seed  Shop\ngarden center\nseed shop"])->getStatusCode());
        self::assertSame(['garden center', 'seed shop'], array_column($this->local->keywords($this->s->acmeProject->id), 'keyword'));

        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/check')->getStatusCode());
        self::assertCount(1, $this->local->queued);
        self::assertStringContainsString('data-refresh-seconds', (string) $owner->get($this->url())->getBody());
        self::assertTrue($this->service()->run($this->s->acmeProject->id, static fn(): bool => true, false));

        self::assertSame('777', $this->local->settings($this->s->acmeProject->id)?->cid, 'the listing is remembered');
        self::assertSame(['cid:777', '43.8563,18.4131,5000'], $this->provider->calls[2]['args'], 'the profile is looked up by its Google ID');
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('last check 2026-10-03', $page);
        self::assertStringContainsString('1 / 2', $page, 'found for 1 of 2 keywords');
        self::assertStringContainsString('not in top 20', $page);
        self::assertStringContainsString('<span class="badge badge-ok">2</span>', $page);
        self::assertStringContainsString('1. Rival Garden', $page);
        self::assertStringContainsString('4.6 ★', $page);
        self::assertStringContainsString('No — claim it in Google Business Profile', $page);
        self::assertMatchesRegularExpression('#<td>Rival Garden</td><td class="num">2</td>#', $page, 'the top local competitor');
        self::assertStringNotContainsString('<td>Acme Shop</td><td class="num">', $page, 'the business itself is not a competitor');

        // A week later, the weekly check is due and positions are compared.
        $this->clock->advance('+7 days');
        $this->provider->maps['garden center'][] = new LocalResult(3, 'Acme Shop', 'shop.acme.test', cid: '777');
        self::assertSame(1, $this->service()->startDue());
        self::assertSame(0, $this->service()->startDue(), 'already queued');
        $this->service()->run($this->s->acmeProject->id, static fn(): bool => true, false);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('<span class="delta delta-good">new</span>', $page);
        self::assertStringContainsString('Rating history', $page);

        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/keywords/delete', ['keyword' => 'garden center'])->getStatusCode());
        self::assertSame(['seed shop'], array_column($this->local->keywords($this->s->acmeProject->id), 'keyword'));
    }

    public function testRankingGrid(): void
    {
        $this->setUpProject();
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringContainsString('No grid checked yet.', (string) $owner->get($this->url())->getBody());

        $invalid = $owner->post($this->url() . '/grid', ['grid_keyword' => 'unknown', 'grid_size' => '4', 'grid_spacing' => '1000']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Choose one of your local keywords.', (string) $invalid->getBody());
        $owner->get($this->url());
        self::assertSame(303, $owner->post($this->url() . '/grid', ['grid_keyword' => 'seed shop', 'grid_size' => '3', 'grid_spacing' => '500'])->getStatusCode());
        self::assertCount(1, $this->local->queuedGrids);
        $owner->get($this->url());
        self::assertSame(409, $owner->post($this->url() . '/grid', ['grid_keyword' => 'seed shop', 'grid_size' => '3', 'grid_spacing' => '500'])->getStatusCode(), 'one grid at a time');

        $calls = 0;
        self::assertFalse($this->service()->runGrid($this->s->acmeProject->id, static function () use (&$calls): bool {
            return ++$calls <= 4;
        }, false), 'stops when time is up');
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('data-refresh-seconds', $page);
        self::assertStringContainsString('grid-pending', $page);

        self::assertTrue($this->service()->runGrid($this->s->acmeProject->id, static fn(): bool => true, false));
        $mapCalls = array_values(array_filter($this->provider->calls, static fn(array $c): bool => $c['call'] === 'maps'));
        self::assertCount(9, $mapCalls, 'every point once');
        self::assertSame('43.8563,18.4131,13z', $mapCalls[4]['args'][1], 'the middle point is the location');
        self::assertNotSame($mapCalls[0]['args'][1], $mapCalls[8]['args'][1]);
        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('3 × 3, 500 m apart', $page);
        self::assertSame(9, substr_count($page, 'grid-cell grid-mid') + substr_count($page, 'grid-cell grid-top'), 'Acme Shop is 2nd everywhere');
        self::assertStringContainsString('grid-center', $page);
        self::assertStringContainsString('Found at 9 of 9 points · average 2.0', $page);
        self::assertStringNotContainsString('data-refresh-seconds', $page);

        $viewer = $this->s->loggedIn($this->s->viewer);
        $viewer->get($this->url());
        self::assertSame(403, $viewer->post($this->url() . '/grid', ['grid_keyword' => 'seed shop', 'grid_size' => '3', 'grid_spacing' => '500'])->getStatusCode());
    }

    public function testFailuresAreRetriedThenShown(): void
    {
        $this->setUpProject();
        $this->provider->fail = new IntegrationException('DataForSEO is busy.', true);
        try {
            $this->service()->run($this->s->acmeProject->id, static fn(): bool => true, false);
            self::fail('A temporary error should be retried.');
        } catch (IntegrationException) {
        }
        $this->provider->fail = new IntegrationException('DataForSEO is busy.', true);
        $this->service()->run($this->s->acmeProject->id, static fn(): bool => true, true);
        self::assertSame('failed', $this->local->settings($this->s->acmeProject->id)?->status);
        self::assertStringContainsString('The last check failed: DataForSEO is busy.', (string) $this->s->loggedIn($this->s->owner)->get($this->url())->getBody());

        // Stopping halfway leaves the rest for the continuation.
        $calls = 0;
        self::assertFalse($this->service()->run($this->s->acmeProject->id, static function () use (&$calls): bool {
            return ++$calls <= 1;
        }, false));
    }

    public function testPermissionsAndIsolation(): void
    {
        $this->setUpProject();
        $viewer = $this->s->loggedIn($this->s->viewer);
        $page = (string) $viewer->get($this->url())->getBody();
        self::assertStringNotContainsString('Save settings', $page);
        self::assertStringNotContainsString('Check now', $page);
        foreach (['/settings', '/keywords', '/keywords/delete', '/check'] as $path) {
            $viewer->get($this->url());
            self::assertSame(403, $viewer->post($this->url() . $path, ['keyword' => 'seed shop', 'keywords' => 'x', 'business_name' => 'X', 'coordinates' => '1,1', 'zoom' => '13', 'language' => 'en'])->getStatusCode(), $path);
        }

        $rival = $this->s->loggedIn($this->s->rivalOwner);
        self::assertSame(404, $rival->get($this->url())->getStatusCode());
        foreach (['/settings', '/keywords', '/keywords/delete', '/check'] as $path) {
            $rival->get('/');
            self::assertSame(404, $rival->post($this->url() . $path, ['keyword' => 'seed shop'])->getStatusCode(), $path);
        }
        self::assertSame([], $this->local->queued);
        self::assertSame(['seed shop'], array_column($this->local->keywords($this->s->acmeProject->id), 'keyword'));
    }

    private function setUpProject(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url() . '/settings', ['business_name' => 'Acme Shop', 'coordinates' => '43.8563, 18.4131', 'zoom' => '13', 'language' => 'en']);
        $owner->get($this->url());
        $owner->post($this->url() . '/keywords', ['keywords' => 'seed shop']);
    }

    private function service(): LocalService
    {
        return $this->s->container->get(LocalService::class);
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/local';
    }

    public function testProfileAgainstWebsiteReviewsAndCategories(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url() . '/settings', ['business_name' => 'Acme Shop', 'coordinates' => '43.8563, 18.4131', 'zoom' => '14', 'language' => 'en']);
        $owner->get($this->url());
        $owner->post($this->url() . '/keywords', ['keywords' => "seed shop\ngarden center"]);
        $this->provider->maps['seed shop'][] = new LocalResult(3, 'Leaf Corner', null, votes: 40, category: 'Garden center');
        $this->provider->maps['seed shop'][0] = new LocalResult(1, 'Rival Garden', 'rival.test', rating: 4.9, votes: 300, category: 'Garden center');
        $this->provider->maps['garden center'][0] = new LocalResult(1, 'Rival Garden', 'rival.test', rating: 4.9, votes: 300, category: 'Garden center');
        $this->provider->profile = new BusinessProfile('Acme Shop', 'Seed supplier', 4.6, 80, true, 'Main St 1', '+44 33 111 222', 'https://shop.acme.test/', 12, '777');
        $owner->get($this->url());
        $owner->post($this->url() . '/check');
        $this->service()->run($this->s->acmeProject->id, static fn(): bool => true, false);

        $page = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('Profile and website', $page);
        self::assertStringContainsString('Run a new <a href="/projects/' . $this->s->acmeProject->publicId . '/site-audit">site audit</a>', $page, 'no audit to compare yet');
        self::assertStringContainsString('Import your Google reviews', $page);
        self::assertMatchesRegularExpression('#<td>Garden center</td><td class="num">3</td>#', $page);
        self::assertStringContainsString('Most businesses in the local pack use <strong>Garden center</strong>', $page);

        $audits = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit::class, $audits);
        $audit = $audits->create($this->s->acmeProject->id, $this->s->acme->id, 'https://shop.acme.test/', 10, 'manual', null);
        $first = $audits->pendingPages($audit->id, 1)[0];
        $audits->pages[$audit->id] = [$first->id => new \SeoControlCenter\Modules\SiteAudit\AuditPage($first->id, 'https://shop.acme.test/contact', 0, 'done', 200, 'text/html', business: ['types' => ['Store'], 'name' => 'Acme Shop', 'phones' => ['033999888'], 'address' => 'Main St 1'])];
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $locations = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryLocations::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemoryLocations::class, $locations);
        $locations->saveReviews($this->s->acmeProject->id, 'primary', ['status' => 'done', 'cid' => '777', 'fetched_at' => '2026-10-02 09:00:00', 'items' => [
            ['id' => 'a', 'author' => 'Ana <b>', 'text' => 'Late', 'rating' => 1.0, 'published' => '2026-09-01 10:00:00 +00:00', 'reply' => null],
            ['id' => 'b', 'author' => 'Ben', 'text' => 'Great', 'rating' => 5.0, 'published' => '2026-08-01 10:00:00 +00:00', 'reply' => 'Thanks'],
        ]]);

        $page = (string) $owner->get($this->url())->getBody();
        self::assertMatchesRegularExpression('#<td>Phone</td><td>\+44 33 111 222</td><td>033999888</td><td><span class="badge badge-warning">Differs</span>#', $page);
        self::assertMatchesRegularExpression('#<td>Address</td><td>Main St 1</td><td>Main St 1</td><td><span class="badge badge-ok">Matches</span>#', $page);
        self::assertStringContainsString('1 of 2 (50%)', $page);
        self::assertStringContainsString('Ana &lt;b&gt; · 1 ★ · 2026-09-01', $page, 'escaped');

        $other = $this->s->loggedIn($this->s->rivalOwner);
        self::assertSame(404, $other->get($this->url())->getStatusCode(), 'a foreign project stays hidden');
    }

    public function testABusinessWithoutAProfileStillCompletesTheCheck(): void
    {
        $this->setUpProject();
        $this->provider->profile = null;
        self::assertTrue($this->service()->run($this->s->acmeProject->id, static fn(): bool => true, false));

        $settings = $this->local->settings($this->s->acmeProject->id);
        self::assertNotSame('failed', $settings?->status, 'finding 42: no profile is not a failed check');
        $page = (string) $this->s->loggedIn($this->s->owner)->get($this->url())->getBody();
        self::assertStringContainsString('No Google Business Profile was found for <strong>Acme Shop</strong>', $page);
        self::assertStringNotContainsString('The last check failed', $page);
        self::assertStringNotContainsString('id="local-nap"', $page);
    }
}

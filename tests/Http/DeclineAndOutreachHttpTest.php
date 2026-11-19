<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Backlinks\PageBacklink;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Backlinks\WebMention;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDisavows;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOutreach;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/** Slow decline and the link outreach tracker (1.8.0). */
final class DeclineAndOutreachHttpTest extends TestCase
{
    private FrozenClock $clock;
    private Scenario $s;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-11-19 08:00:00', new \DateTimeZone('UTC')));
        $this->s = new Scenario([], $this->clock);
    }

    public function testSlowDecline(): void
    {
        $search = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemorySearchPerformance::class, $search);
        $project = $this->s->acmeProject->id;
        $url = '/projects/' . $this->s->acmeProject->publicId . '/performance/decline';
        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringContainsString('Connect Search Console first', (string) $viewer->get($url)->getBody());

        // Daily clicks per quarter (oldest first): /slow slides, /sudden drops at once, /wobbly recovers, /small is too small.
        $pages = [
            '/slow' => [[10, 100, 3.0], [8, 100, 3.2], [7, 95, 3.5], [5, 90, 6.0]],
            '/sudden' => [[10, 100, 3.0], [10, 100, 3.0], [10, 100, 3.0], [2, 100, 3.0]],
            '/wobbly' => [[10, 100, 3.0], [6, 100, 3.0], [9, 100, 3.0], [5, 100, 3.0]],
            '/ctr' => [[4, 200, 2.0], [3, 200, 2.0], [3, 200, 2.0], [2, 200, 2.1]],
            '/small' => [[0, 10, 9.0], [0, 10, 9.0], [0, 10, 9.0], [0, 10, 9.0]],
        ];
        $end = new \DateTimeImmutable('2026-11-18');
        $start = $end->modify('-363 days');
        for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
            $quarter = min(3, intdiv((int) $start->diff($day)->days, 91));
            $date = $day->format('Y-m-d');
            $search->daily[$project][$date] = new Metrics(10, 100, 5.0);
            $search->details[$project][$date]['page'] = array_map(
                static fn(string $path, array $q): DimensionRow => new DimensionRow('https://shop.acme.test' . $path, new Metrics($q[$quarter][0], $q[$quarter][1], $q[$quarter][2])),
                array_keys($pages),
                array_values($pages),
            );
        }
        $html = (string) $viewer->get($url)->getBody();
        self::assertMatchesRegularExpression('#Pages declining</h2><p class="stat-value">2</p><p class="stat-note">of 4 with clicks#', $html);
        self::assertMatchesRegularExpression('#/slow</a></td>\s*<td><span class="badge">Lost positions</span></td>\s*<td class="numeric">910</td>\s*<td class="numeric">728</td>\s*<td class="numeric">637</td>\s*<td class="numeric">455</td>\s*<td class="numeric"><span class="delta delta-bad">−50 %</span>#', $html);
        self::assertMatchesRegularExpression('#/ctr</a></td>\s*<td><span class="badge">Fewer clicks per view</span>#', $html);
        self::assertStringNotContainsString('/sudden', $html, 'One sudden drop is not a slow decline.');
        self::assertStringNotContainsString('/wobbly', $html, 'A quarter that recovers breaks the slide.');
        self::assertStringContainsString('/performance/decline', (string) $viewer->get('/projects/' . $this->s->acmeProject->publicId . '/performance')->getBody());
        self::assertSame(404, $viewer->get('/projects/' . $this->s->rivalProject->publicId . '/performance/decline')->getStatusCode());

        // Less than a year imported.
        foreach (array_keys($search->daily[$project]) as $date) {
            if ($date < '2026-03-01') {
                unset($search->daily[$project][$date], $search->details[$project][$date]);
            }
        }
        self::assertStringContainsString('Not enough history yet', (string) $viewer->get($url)->getBody());
    }

    public function testOutreachTracker(): void
    {
        $research = $this->s->container->get(InMemoryResearch::class);
        $outreach = $this->s->container->get(InMemoryOutreach::class);
        $disavows = $this->s->container->get(InMemoryDisavows::class);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        self::assertInstanceOf(InMemoryOutreach::class, $outreach);
        self::assertInstanceOf(InMemoryDisavows::class, $disavows);
        $project = $this->s->acmeProject;
        $url = '/projects/' . $project->publicId . '/backlinks/outreach';
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringContainsString('No suggestions.', (string) $owner->get($url)->getBody());

        $store = function (string $kind, array $items) use ($research): void {
            $this->store($research, $kind, $items);
        };
        $store('bl_domains', [(new ReferringDomain('already-linking.test', 40))->toArray()]);
        $store('bl_mentions', [
            (new WebMention('https://blog.news.test/acme-review', 'blog.news.test', 'Acme review', null, 60, null, null))->toArray(),
            (new WebMention('https://already-linking.test/post', 'already-linking.test', 'Linked', null, 40, null, null))->toArray(),
            (new WebMention('https://spam.test/x', 'spam.test', 'Spam', null, 1, null, null))->toArray(),
        ]);
        $store('bl_lost_links', [(new PageBacklink('https://www.old-friend.test/links', 'https://shop.acme.test/shoes', 'Acme shoes', 50, true, '2024-01-01', '2026-11-10'))->toArray()]);
        $disavows->add($project->id, $project->workspaceId, ['spam.test' => 90], $this->s->owner->id);

        $html = (string) $owner->get($url)->getBody();
        self::assertMatchesRegularExpression('#<td>blog\.news\.test</td>\s*<td class="numeric">60</td>\s*<td class="small">Unlinked mention#', $html);
        self::assertMatchesRegularExpression('#<td>old-friend\.test</td>\s*<td class="numeric">50</td>\s*<td class="small">Lost link#', $html);
        self::assertStringNotContainsString('<td>already-linking.test</td>', $html, 'Sites that link already are not suggested.');
        self::assertStringNotContainsString('<td>spam.test</td>', $html, 'Disavowed sites are not suggested.');

        $owner->get('/account');
        $owner->post($url, ['action' => 'add', 'domain' => 'old-friend.test', 'source' => 'lost', 'page' => 'https://www.old-friend.test/links', 'target' => 'https://shop.acme.test/shoes']);
        $owner->post($url, ['action' => 'add', 'domain' => 'https://www.Partner.test/about', 'source' => 'bogus']);
        $owner->post($url, ['action' => 'add', 'domain' => 'shop.acme.test']);
        self::assertSame(['old-friend.test', 'partner.test'], array_keys($outreach->entries[$project->id]));
        self::assertSame('manual', $outreach->entries[$project->id]['partner.test']['source']);
        self::assertSame('https://shop.acme.test/shoes', $outreach->entries[$project->id]['old-friend.test']['targetUrl']);
        $owner->post($url, ['action' => 'add', 'domain' => 'partner.test']);
        self::assertStringContainsString('partner.test is already on the list.', (string) $owner->get($url)->getBody());
        self::assertStringNotContainsString('<td>old-friend.test</td>', (string) $owner->get($url)->getBody(), 'Listed sites leave the suggestions.');

        $owner->post($url, ['action' => 'status', 'domain' => 'old-friend.test', 'status' => 'contacted', 'note' => 'Wrote to the editor']);
        self::assertSame(['contacted', 'Wrote to the editor'], [$outreach->entries[$project->id]['old-friend.test']['status'], $outreach->entries[$project->id]['old-friend.test']['note']]);
        $owner->post($url, ['action' => 'status', 'domain' => 'old-friend.test', 'status' => 'sent']);
        self::assertSame('contacted', $outreach->entries[$project->id]['old-friend.test']['status'], 'Unknown statuses are refused.');
        self::assertStringNotContainsString('badge-ok">Link found', (string) $owner->get($url)->getBody());

        $this->clock->advance('+3 days');
        $store('bl_domains', [(new ReferringDomain('old-friend.test', 50))->toArray()]);
        $html = (string) $owner->get($url)->getBody();
        self::assertStringContainsString('old-friend.test <span class="badge badge-ok">Link found</span>', $html);
        self::assertMatchesRegularExpression('#Contacted</h2><p class="stat-value">1</p>#', $html);
        self::assertStringContainsString('Contacted <span class="muted">1</span>', $html);
        self::assertStringNotContainsString('partner.test', (string) $owner->get($url . '?status=contacted')->getBody());

        $owner->post($url, ['action' => 'remove', 'domain' => 'partner.test']);
        self::assertSame(['old-friend.test'], array_keys($outreach->entries[$project->id]));

        $viewer = $this->s->loggedIn($this->s->viewer);
        $html = (string) $viewer->get($url)->getBody();
        self::assertStringContainsString('Wrote to the editor', $html);
        self::assertStringNotContainsString('Suggestions (', $html);
        $viewer->get('/account');
        self::assertSame(403, $viewer->post($url, ['action' => 'add', 'domain' => 'x.test'])->getStatusCode());
        self::assertSame(404, $viewer->get('/projects/' . $this->s->rivalProject->publicId . '/backlinks/outreach')->getStatusCode());
        self::assertStringContainsString('/backlinks/outreach', (string) $viewer->get('/projects/' . $project->publicId . '/backlinks')->getBody());
    }

    /** @param array<mixed> $items */
    private function store(InMemoryResearch $research, string $kind, array $items): void
    {
        $project = $this->s->acmeProject;
        $research->queue($project->id, $project->workspaceId, $kind, '', ['domain' => 'shop.acme.test'], null);
        $research->complete($project->id, $kind, '', array_values(array_filter($items, is_array(...))));
    }
}

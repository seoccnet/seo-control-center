<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\InternalLinks\LinkRank;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywordTargets;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/** Share of voice, target pages and internal link strength (1.8.0). */
final class VoiceAndTargetsHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario([], new FrozenClock(new \DateTimeImmutable('2026-11-19 08:00:00', new \DateTimeZone('UTC'))));
    }

    public function testShareOfVoice(): void
    {
        $competitors = $this->s->container->get(InMemoryCompetitors::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        self::assertInstanceOf(InMemoryCompetitors::class, $competitors);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        $project = $this->s->acmeProject->id;
        $url = '/projects/' . $this->s->acmeProject->publicId . '/competitors/share-of-voice';
        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringContainsString('Add at least one competitor', (string) $viewer->get($url)->getBody());

        $rival = $competitors->add($project, 'rival.test', 'manual', $this->s->owner->id) ?? self::fail('Not added.');
        $late = $competitors->add($project, 'late.test', 'manual', $this->s->owner->id) ?? self::fail('Not added.');
        $day = static fn(string $date, float $visibility): RankingDay => new RankingDay($date, 10, 5, 1, 4, 8.0, $visibility);
        foreach (['2026-10-01' => 10.0, '2026-10-15' => 15.0, '2026-11-15' => 20.0] as $date => $own) {
            $rankings->days[$project . ':desktop:' . $date] = $day($date, $own);
            $competitors->days[$rival->id . ':desktop:' . $date] = $day($date, 30.0);
        }
        self::assertStringContainsString('Share of voice needs a rank check on which every competitor was measured', (string) $viewer->get($url)->getBody());
        // The late competitor was measured from mid-October only.
        $competitors->days[$late->id . ':desktop:2026-10-15'] = $day('2026-10-15', 15.0);
        $competitors->days[$late->id . ':desktop:2026-11-15'] = $day('2026-11-15', 30.0);

        $html = (string) $viewer->get($url)->getBody();
        self::assertStringContainsString('Latest check · 2026-11-15', $html);
        self::assertMatchesRegularExpression('#acme\.test <span class="badge">You</span></td>\s*<td class="numeric">25\.0 %</td>.*?<span class="delta delta-good">\+0\.0 pts|acme\.test <span class="badge">You</span></td>\s*<td class="numeric">25\.0 %</td>#s', $html);
        self::assertMatchesRegularExpression('#rival\.test</td>\s*<td class="numeric">37\.5 %</td>.*?<span class="delta delta-bad">-12\.5 pts</span>#s', $html, '30 of 80 now, 30 of 60 on 2026-10-15.');
        self::assertStringContainsString('1 of the 3 checks in the last 180 days are left out', $html);
        self::assertStringContainsString('/competitors/share-of-voice', (string) $viewer->get('/projects/' . $this->s->acmeProject->publicId . '/competitors')->getBody());
        self::assertSame(404, $viewer->get('/projects/' . $this->s->rivalProject->publicId . '/competitors/share-of-voice')->getStatusCode());
    }

    public function testTargetPages(): void
    {
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        $targets = $this->s->container->get(InMemoryKeywordTargets::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        self::assertInstanceOf(InMemoryKeywordTargets::class, $targets);
        $project = $this->s->acmeProject->id;
        $url = '/projects/' . $this->s->acmeProject->publicId . '/rankings/target-pages';
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertStringContainsString('No checked keywords yet', (string) $owner->get($url)->getBody());

        $keywords->add($project, ['red shoes', 'blue boots', 'green hats'], KeywordLocale::default(), $this->s->owner->id);
        $ids = array_column($keywords->keywords[$project], 'id', 'keyword');
        $public = array_column($keywords->keywords[$project], 'public_id', 'keyword');
        $snap = static fn(string $keyword, string $date, ?int $position, ?string $page): RankSnapshot => new RankSnapshot($ids[$keyword], $project, SerpDevice::Desktop, $date, $position, $position, $page !== null ? 'https://shop.acme.test' . $page : null, [], []);
        foreach (['2026-10-01' => '/red', '2026-10-08' => '/shoes', '2026-10-15' => '/red', '2026-10-22' => '/shoes'] as $date => $page) {
            $rankings->storeSnapshot($snap('red shoes', $date, 4, $page));
        }
        $rankings->storeSnapshot($snap('blue boots', '2026-10-22', 7, '/boots/'));
        $rankings->storeSnapshot($snap('green hats', '2026-10-22', null, null));

        $html = (string) $owner->get($url)->getBody();
        self::assertMatchesRegularExpression('#Switching pages</h2><p class="stat-value">1</p>#', $html);
        self::assertMatchesRegularExpression('#No target yet</h2><p class="stat-value">3</p>#', $html);

        $owner->get('/account');
        $owner->post($url, ['keyword' => $public['red shoes'], 'url' => '/red/']);
        $owner->post($url, ['keyword' => $public['green hats'], 'url' => 'https://www.shop.acme.test/hats#top']);
        self::assertSame(['red shoes' => 'https://shop.acme.test/red/', 'green hats' => 'https://www.shop.acme.test/hats'], [
            'red shoes' => $targets->targets[$project][$public['red shoes']] ?? null,
            'green hats' => $targets->targets[$project][$public['green hats']] ?? null,
        ]);
        $owner->post($url, ['keyword' => $public['blue boots'], 'url' => 'https://elsewhere.test/boots']);
        self::assertArrayNotHasKey($public['blue boots'], $targets->targets[$project], 'Only pages of the project.');
        self::assertStringContainsString('Enter a page of shop.acme.test', (string) $owner->get($url)->getBody());

        $html = (string) $owner->get($url)->getBody();
        self::assertMatchesRegularExpression('#Another page ranks</h2><p class="stat-value">1</p>#', $html, 'red shoes ranks with /shoes, the target is /red/.');
        self::assertMatchesRegularExpression('#green hats</a></td>\s*<td><span class="badge badge-warning">Not ranking</span>#', $html);
        $owner->post($url, ['action' => 'adopt']);
        self::assertSame('https://shop.acme.test/boots/', $targets->targets[$project][$public['blue boots']] ?? null);
        $owner->post($url, ['keyword' => $public['blue boots'], 'url' => 'https://WWW.shop.acme.test/boots']);
        self::assertMatchesRegularExpression('#Target ranks</h2><p class="stat-value">1</p>#', (string) $owner->get($url)->getBody(), 'www. and a trailing slash do not matter.');
        $owner->post($url, ['keyword' => $public['green hats'], 'url' => '']);
        self::assertArrayNotHasKey($public['green hats'], $targets->targets[$project]);
        self::assertStringContainsString('blue boots', (string) $owner->get($url . '?filter=match')->getBody());
        self::assertStringNotContainsString('blue boots', (string) $owner->get($url . '?filter=switching')->getBody());

        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringNotContainsString('name="url"', (string) $viewer->get($url)->getBody());
        $viewer->get('/account');
        self::assertSame(403, $viewer->post($url, ['keyword' => $public['red shoes'], 'url' => '/x'])->getStatusCode());
        self::assertSame(404, $viewer->get('/projects/' . $this->s->rivalProject->publicId . '/rankings/target-pages')->getStatusCode());
        self::assertStringContainsString('/rankings/target-pages', (string) $viewer->get('/projects/' . $this->s->acmeProject->publicId . '/rankings')->getBody());
    }

    public function testLinkRank(): void
    {
        // 1 links to 2 and 3; 2 and 3 link back to 1; 4 is linked from 3 only.
        $ranks = LinkRank::ranks([1, 2, 3, 4], [[1, 2], [1, 3], [2, 1], [3, 1], [3, 4], [3, 3], [9, 1]]);
        self::assertEqualsWithDelta(1.0, array_sum($ranks), 1.0e-9);
        self::assertGreaterThan($ranks[2], $ranks[1]);
        self::assertGreaterThan($ranks[4], $ranks[2]);
        $percentiles = LinkRank::percentiles($ranks);
        ksort($percentiles);
        self::assertSame([1 => 100, 2 => 33, 3 => 33, 4 => 0], $percentiles, 'Equal ranks share a percentile.');
        self::assertSame([], LinkRank::ranks([], []));
    }

    public function testLinkStrengthPage(): void
    {
        $audits = $this->s->container->get(InMemorySiteAudit::class);
        $search = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        self::assertInstanceOf(InMemorySearchPerformance::class, $search);
        $project = $this->s->acmeProject->id;
        $url = '/projects/' . $this->s->acmeProject->publicId . '/site-audit/link-strength';
        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertStringContainsString('Run a site audit first', (string) $viewer->get($url)->getBody());

        $audit = $audits->create($project, $this->s->acme->id, 'https://shop.acme.test/', 100, 'manual', null);
        $first = $audits->pendingPages($audit->id, 1)[0]->id;
        $audits->pages[$audit->id] = [];
        $pages = ['/' => 0, '/about' => 1, '/contact' => 1, '/guide' => 4, '/tips' => 1, '/deep' => 5];
        $ids = [];
        foreach (array_keys($pages) as $i => $path) {
            $ids[$path] = $first + $i;
            $audits->pages[$audit->id][$first + $i] = new AuditPage($first + $i, 'https://shop.acme.test' . $path, $pages[$path], 'done', 200, 'text/html', title: $path);
        }
        $audits->links[$audit->id] = [];
        $link = static function (string $from, string $to) use ($audits, $audit, $ids): void {
            $audits->links[$audit->id][$ids[$from] . ':' . $ids[$to]] = [$ids[$from], $ids[$to]];
        };
        // Every page links to /about and /contact (the menu); they link to each other.
        foreach (['/', '/guide', '/deep', '/tips', '/about', '/contact'] as $from) {
            foreach (['/about', '/contact'] as $to) {
                if ($from !== $to) {
                    $link($from, $to);
                }
            }
        }
        $link('/', '/tips');
        $link('/tips', '/guide');
        $link('/guide', '/deep');
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $html = (string) $viewer->get($url)->getBody();
        self::assertStringContainsString('Link Search Console to compare', $html);
        self::assertMatchesRegularExpression('#Internal links</h2><p class="stat-value">13</p>#', $html);

        for ($day = new \DateTimeImmutable('2026-08-21'); $day <= new \DateTimeImmutable('2026-11-18'); $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $search->daily[$project][$date] = new Metrics(10, 100, 5.0);
            $search->details[$project][$date]['page'] = [
                new DimensionRow('https://shop.acme.test/guide', new Metrics(3, 50, 4.0)),
                new DimensionRow('https://shop.acme.test/', new Metrics(1, 20, 2.0)),
                new DimensionRow('https://shop.acme.test/tips', new Metrics(1, 20, 6.0)),
            ];
        }
        $html = (string) $viewer->get($url)->getBody();
        self::assertMatchesRegularExpression('#Earn clicks, weakly linked</h2><p class="stat-value">1</p>#', $html);
        self::assertMatchesRegularExpression('#/guide</a></td>\s*<td class="numeric">\d+</td>.*?<td class="numeric">4</td>\s*<td class="numeric">270</td>#s', $html, 'Four clicks from the start, 270 clicks.');
        $strong = (string) $viewer->get($url . '?show=no_searches')->getBody();
        self::assertStringContainsString('/about</a>', $strong);
        self::assertStringContainsString('/contact</a>', $strong);
        self::assertStringNotContainsString('>/</a>', $strong, 'The start page is left out.');
        self::assertStringContainsString('/deep</a>', (string) $viewer->get($url . '?show=all')->getBody());
        self::assertStringContainsString('/site-audit/link-strength', (string) $viewer->get('/projects/' . $this->s->acmeProject->publicId . '/site-audit')->getBody());
        self::assertSame(404, $viewer->get('/projects/' . $this->s->rivalProject->publicId . '/site-audit/link-strength')->getStatusCode());
    }
}

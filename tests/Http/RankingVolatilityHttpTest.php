<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\Volatility;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Scenario;

/** Ranking volatility (1.7.0). */
final class RankingVolatilityHttpTest extends TestCase
{
    public function testLevelsAgainstTheUsualMovement(): void
    {
        $calm = array_map(static fn(int $i): array => ['date' => sprintf('2026-11-%02d', $i), 'pairs' => 10, 'moved' => 2, 'average' => 0.8], range(1, 10));
        $result = Volatility::assess([...$calm, ['date' => '2026-11-11', 'pairs' => 10, 'moved' => 9, 'average' => 1.3], ['date' => '2026-11-12', 'pairs' => 10, 'moved' => 9, 'average' => 2.4], ['date' => '2026-11-13', 'pairs' => 3, 'moved' => 3, 'average' => 9.0]]);
        self::assertSame(0.8, $result['usual']);
        self::assertCount(12, $result['days'], 'Days with fewer than five keyword pairs are left out.');
        self::assertSame([Volatility::CALM, Volatility::MOVING, Volatility::TURBULENT], array_column(array_slice($result['days'], 9), 'level'));
        self::assertSame('2026-11-12', $result['latest']['date'] ?? null);
        self::assertSame(3.0, $result['latest']['ratio'] ?? null);

        self::assertSame(Volatility::CALM, Volatility::assess($calm)['days'][0]['level'], 'Ten days are enough.');
        self::assertSame(Volatility::UNKNOWN, Volatility::level(5.0, null));
        self::assertNull(Volatility::assess(array_slice($calm, 0, 9))['usual']);
        self::assertSame(Volatility::CALM, Volatility::level(0.4, 0.01), 'Under half a place is calm.');
        self::assertSame(Volatility::TURBULENT, Volatility::level(0.7, 0.0), 'A project that never moves: real movement stands out.');
    }

    public function testVolatilityPage(): void
    {
        $s = new Scenario([], new FrozenClock(new \DateTimeImmutable('2026-11-19 08:00:00', new \DateTimeZone('UTC'))));
        $keywords = $s->container->get(InMemoryKeywords::class);
        $rankings = $s->container->get(InMemoryRankings::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        $project = $s->acmeProject->id;
        $url = '/projects/' . $s->acmeProject->publicId . '/rankings/volatility';
        $viewer = $s->loggedIn($s->viewer);
        self::assertStringContainsString('No movement measured yet', (string) $viewer->get($url)->getBody());

        $keywords->add($project, ['k1', 'k2', 'k3', 'k4', 'k5', 'k6'], KeywordLocale::default(), $s->owner->id);
        $ids = array_values(array_column($keywords->keywords[$project], 'id'));
        $base = [5, 3, 4, 7, 9, 15];
        for ($d = 0; $d < 14; $d++) {
            $date = sprintf('2026-11-%02d', $d + 1);
            foreach ($ids as $i => $id) {
                // One keyword moves a place each day; the rest stay.
                $position = $i === 0 ? 5 + $d % 2 : $base[$i];
                $rankings->storeSnapshot(new RankSnapshot($id, $project, SerpDevice::Desktop, $date, $position, $position, null, [], []));
            }
        }
        foreach ($ids as $i => $id) {
            $rankings->storeSnapshot(new RankSnapshot($id, $project, SerpDevice::Desktop, '2026-11-15', $base[$i] + 7, $base[$i] + 7, null, [], []));
            $rankings->storeSnapshot(new RankSnapshot($id, $project, SerpDevice::Mobile, '2026-11-16', 90, 90, null, [], []));
            // Imported history is left out.
            $rankings->storeSnapshot(new RankSnapshot($id, $project, SerpDevice::Desktop, '2026-11-16', 1, 1, null, [], []));
            $rankings->imported[$id . ':desktop:2026-11-16'] = true;
        }

        $html = (string) $viewer->get($url)->getBody();
        self::assertMatchesRegularExpression('#Latest check</h2><p class="stat-value"><span class="badge badge-error">Turbulent</span></p><p class="stat-note">2026-11-15#', $html);
        self::assertMatchesRegularExpression('#Average move</h2><p class="stat-value">6\.67</p>#', $html, 'Positions beyond 20 count as 21.');
        self::assertMatchesRegularExpression('#Usual move</h2><p class="stat-value">0\.17</p>#', $html);
        self::assertMatchesRegularExpression('#Keywords that moved</h2><p class="stat-value">6 / 6</p>#', $html);
        self::assertMatchesRegularExpression('#<td>2026-11-15</td>.*?November 2026 core update</td>#s', $html);
        self::assertMatchesRegularExpression('#<td>2026-11-09</td>\s*<td><span class="badge badge-ok">Calm</span>.*?<td class="small"></td>#s', $html, 'Before the update started.');
        self::assertStringNotContainsString('2026-11-16', $html);
        self::assertStringNotContainsString('<td>2026-11-01</td>', $html, 'The first check has nothing to compare with.');
        self::assertStringContainsString('/rankings/volatility', (string) $viewer->get('/projects/' . $s->acmeProject->publicId . '/rankings')->getBody());
        self::assertSame(404, $viewer->get('/projects/' . $s->rivalProject->publicId . '/rankings/volatility')->getStatusCode());
    }
}

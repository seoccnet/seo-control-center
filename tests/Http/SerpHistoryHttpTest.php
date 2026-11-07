<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankSnapshot;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Modules\Alerts\AlertDetector;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\SerpHistory\SerpHistoryService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemorySerpEvents;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Results-page change history (ADR-039, E1): recorded after a check day,
 * idempotent, the Changes page, the alert, MCP and the daily catch-up.
 */
final class SerpHistoryHttpTest extends TestCase
{
    private Scenario $s;

    private string $today;

    /** @var array{0: array{id: int, public_id: string, keyword: string}, 1: array{id: int, public_id: string, keyword: string}} */
    private array $kw;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $project = $this->s->acmeProject->id;
        $keywords = $this->s->container->get(InMemoryKeywords::class);
        $rankings = $this->s->container->get(InMemoryRankings::class);
        self::assertInstanceOf(InMemoryKeywords::class, $keywords);
        self::assertInstanceOf(InMemoryRankings::class, $rankings);
        $now = $this->s->container->make(\SeoControlCenter\Core\Support\Clock::class)->now();
        $this->today = $now->format('Y-m-d');
        $week = $now->modify('-7 days')->format('Y-m-d');
        $old = $now->modify('-40 days')->format('Y-m-d');
        $keywords->add($project, ['tomato seeds', 'organic seeds'], KeywordLocale::default(), $this->s->owner->id);
        $rankings->saveSettings($project, new RankingSettings(true, SerpDevice::Desktop, RankingSettings::DAILY), $this->s->owner->id);
        $rankings->projects[$project] = ['workspace_id' => $this->s->acme->id, 'domain' => 'shop.acme.test'];
        $this->s->container->make(CompetitorRepository::class)->add($project, 'rival.test', 'manual', $this->s->owner->id);
        $rows = $keywords->keywords[$project];
        $this->kw = [$rows[0], $rows[1]];
        $top = static fn(array $urls): array => array_map(static fn(int $i, string $u): array => ['position' => $i + 1, 'domain' => (string) parse_url($u, PHP_URL_HOST), 'url' => $u], array_keys($urls), $urls);
        $store = function (int $id, string $day, ?int $position, array $features, array $urls) use ($project, $rankings, $top): void {
            $rankings->storeSnapshot(new RankSnapshot($id, $project, SerpDevice::Desktop, $day, $position, $position, $position !== null ? 'https://shop.acme.test/seeds' : null, array_values(array_map('strval', $features)), $top($urls)));
        };
        $store($this->kw[0]['id'], $week, 6, ['images'], ['https://a.test/1', 'https://b.test/2', 'https://c.test/3']);
        $store($this->kw[1]['id'], $week, 15, [], ['https://a.test/x']);
        $store($this->kw[0]['id'], $this->today, 14, ['images', 'ai_overview'], ['https://rival.test/tomato-seeds', 'https://a.test/1', 'https://b.test/2']);
        $store($this->kw[1]['id'], $this->today, 15, [], ['https://a.test/x']);
        $store($this->kw[1]['id'], $old, 30, [], ['https://z.test/old']);
    }

    public function testChangesAreRecordedOnceAndShown(): void
    {
        $observer = $this->s->container->make(SerpObserver::class);
        $observer->dayChecked($this->s->acmeProject->id, $this->s->acme->id, SerpDevice::Desktop, $this->today);
        $observer->dayChecked($this->s->acmeProject->id, $this->s->acme->id, SerpDevice::Desktop, $this->today);
        $events = $this->s->container->get(InMemorySerpEvents::class);
        self::assertInstanceOf(InMemorySerpEvents::class, $events);
        $kinds = array_column($events->rows, 'kind');
        sort($kinds);
        self::assertSame(['competitor_entered', 'feature_added', 'new_url', 'own_left'], $kinds, 'Once each, after the check day; “feeding seeds” did not change.');

        $owner = $this->s->loggedIn($this->s->owner);
        $url = '/projects/' . $this->s->acmeProject->publicId . '/rankings/changes';
        $page = html_entity_decode((string) $owner->get($url)->getBody());
        self::assertStringContainsString('Results-page changes', $page);
        self::assertStringContainsString('AI Overview appeared for “tomato seeds”', $page);
        self::assertStringContainsString('You left the top 10 for “tomato seeds”: #6 → #14', $page);
        self::assertStringContainsString('rival.test entered the top 10 for “tomato seeds” at #1, above you', $page);
        self::assertStringContainsString('4 changes', $page);
        self::assertMatchesRegularExpression('#rival\.test</a></td><td class="numeric">1</td><td class="numeric">1</td><td class="numeric">0</td>#', $page, 'One new URL, one keyword entered, none left.');
        $features = html_entity_decode((string) $owner->get($url . '?kind=features')->getBody());
        self::assertStringContainsString('1 change matches the filter', $features);
        self::assertStringNotContainsString('#6 → #14', $features);
        self::assertSame(200, $this->s->loggedIn($this->s->viewer)->get($url)->getStatusCode());
        self::assertSame(404, $this->s->loggedIn($this->s->rivalOwner)->get($url)->getStatusCode());

        $alert = array_values(array_filter($this->detect(), static fn(array $e): bool => $e['type'] === AlertType::SerpChange));
        self::assertCount(1, $alert);
        self::assertSame('Acme Shop: results pages of tracked keywords changed', $alert[0]['title']);
        self::assertContains('You left the top 10 for “tomato seeds”: #6 → #14 (' . $this->today . ').', $alert[0]['lines']);
        self::assertContains('rival.test entered the top 10 for “tomato seeds” at #1, above you (' . $this->today . ').', $alert[0]['lines']);
        self::assertNotContains('AI Overview appeared for “tomato seeds” (' . $this->today . ').', $alert[0]['lines'], 'An AI Overview alerts only where you rank in the top 10 (here #14).');

        $mcp = $this->call('serp_changes', ['project_id' => $this->s->acmeProject->publicId, 'kind' => 'competitors']);
        self::assertSame(2, $mcp['total']);
        self::assertSame(['competitor_entered', 'new_url'], array_values(array_unique(array_column($mcp['changes'], 'kind'))));
        self::assertSame(['rival.test' => ['new_urls' => 1, 'entered' => 1, 'left' => 0]], $mcp['competitors_30_days']);
    }

    public function testCatchUpBackfillsTrackedProjectsWithoutDuplicates(): void
    {
        $history = $this->s->container->make(SerpHistoryService::class);
        $first = $history->catchUp();
        self::assertSame(4, $first, 'The first pass looks back 90 days; the check 40 days ago is more than 14 days before the next one, so it is not compared.');
        self::assertSame(0, $history->catchUp(), 'Running again stores nothing twice.');
    }

    /** @return list<array<string, mixed>> */
    private function detect(): array
    {
        $detector = $this->s->container->get(AlertDetector::class);
        self::assertInstanceOf(AlertDetector::class, $detector);

        return $detector->project($this->s->acmeProject->id, $this->s->acmeProject->publicId, 'Acme Shop');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments): array
    {
        $token = $this->s->container->make(\SeoControlCenter\Modules\Mcp\McpTokenService::class)->create($this->s->owner, $this->s->acme, ['name' => 'test', 'scopes' => ['projects:read', 'rankings:read'], 'project_access' => 'all', 'expires' => '30'], ClientInfo::cli())['plaintext'];
        $response = $this->s->container->make(Kernel::class)->handle(
            (new ServerRequest('POST', 'http://scc.test/mcp', ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token], null, '1.1', ['REMOTE_ADDR' => '203.0.113.22']))
                ->withBody(Stream::create((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]))),
        );
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertFalse($data['result']['isError'] ?? true, (string) json_encode($data));

        return $data['result']['structuredContent'];
    }
}

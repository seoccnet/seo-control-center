<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\BusinessContextRepository;
use SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository;
use SeoControlCenter\Domain\Rankings\AiOverview;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpResult;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;
use SeoControlCenter\Modules\Research\ResearchRepository;

/**
 * Business profiles, keyword ratings, the latest results page per tracked
 * keyword and the new research kinds against MySQL/MariaDB.
 */
final class OpportunityIntelligencePersistenceTest extends FullStackTestCase
{
    public function testBusinessProfileAndRatings(): void
    {
        [$project, $user] = $this->project();
        $repo = $this->app->make(BusinessContextRepository::class);
        self::assertNull($repo->find($project));

        $repo->save($project, new BusinessContext('ecommerce', 'sales', 'Running shoes', 'runners', ['running shoes', 'socks'], ['used'], 35, null), $user);
        $saved = $repo->find($project);
        self::assertNotNull($saved);
        self::assertSame(['ecommerce', 'sales', 'Running shoes', 'runners', ['running shoes', 'socks'], ['used'], 35, null], [$saved->model, $saved->goal, $saved->description, $saved->audience, $saved->offerings, $saved->exclusions, $saved->maxDifficulty, $saved->maxStrength]);
        self::assertNotNull($saved->updatedAt);

        self::assertSame([], $saved->markets, 'No markets set is stored as NULL and read back empty.');
        $repo->save($project, new BusinessContext('ecommerce', 'sales', markets: ['2276:de', '2040:de']), $user);
        self::assertSame(['2276:de', '2040:de'], $repo->find($project)?->markets);

        $repo->save($project, new BusinessContext('leads', 'leads', maxStrength: 25), $user);
        self::assertSame(['leads', [], 25, []], [$repo->find($project)?->model, $repo->find($project)?->offerings, $repo->find($project)?->maxStrength, $repo->find($project)?->markets]);

        $repo->rate($project, '  Red  Shoes ', 3, $user);
        $repo->rate($project, 'red shoes', 1, $user);
        $repo->rate($project, 'blue shoes', 9, $user);
        self::assertSame(['blue shoes' => 3, 'red shoes' => 1], $repo->ratings($project), 'Normalized, upserted and clamped.');
        $repo->rate($project, 'RED SHOES', null, $user);
        self::assertSame(['blue shoes' => 3], $repo->ratings($project));

        $repo->delete($project);
        self::assertNull($repo->find($project));
        $this->db->execute('DELETE FROM projects WHERE id = ?', [$project]);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM opportunity_keyword_ratings'));
    }

    public function testLatestResultsPageKeepsTheNewestCheckOnly(): void
    {
        [$project] = $this->project();
        $this->db->execute(
            "INSERT INTO project_keywords (public_id, project_id, keyword, keyword_hash, location_code, language_code, created_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000141', ?, 'running shoes', ?, 2840, 'en', UTC_TIMESTAMP())",
            [$project, hash('sha256', 'running shoes', true)],
        );
        $keyword = $this->db->lastInsertId();
        $repo = $this->app->make(SerpLandscapeRepository::class);
        $serp = static fn(string $date, array $domains, bool $overview): SerpSnapshot => new SerpSnapshot(
            'running shoes',
            KeywordLocale::default(),
            SerpDevice::Desktop,
            $date . ' 08:00:00',
            1000,
            array_map(static fn(int $i, string $d): SerpResult => new SerpResult($i + 1, $i + 1, 'https://' . $d . '/shoes', $d, str_repeat('Title ', 50), null), array_keys($domains), $domains),
            ['people_also_ask'],
            $overview ? new AiOverview([]) : null,
        );

        $repo->record($project, $keyword, '2026-10-02', $serp('2026-10-02', ['www.big.test', 'small.test', 'big.test'], true));
        $repo->record($project, $keyword, '2026-09-25', $serp('2026-09-25', ['old.test'], false));
        $latest = $repo->latest($project, SerpDevice::Desktop);
        self::assertSame(['running shoes'], array_keys($latest));
        self::assertSame('2026-10-02', $latest['running shoes']->checkedOn, 'An older check never overwrites a newer one.');
        self::assertTrue($latest['running shoes']->aiOverview);
        self::assertSame(['people_also_ask'], $latest['running shoes']->features);
        self::assertSame(200, mb_strlen((string) $latest['running shoes']->results[0]['title']), 'Titles are bounded.');
        self::assertSame(['big.test', 'small.test'], $repo->domains($project, SerpDevice::Desktop, 10));
        self::assertSame([], $repo->latest($project, SerpDevice::Mobile));

        $repo->record($project, $keyword, '2026-10-03', $serp('2026-10-03', ['new.test'], false));
        self::assertSame(['new.test'], $repo->domains($project, SerpDevice::Desktop, 10));
        self::assertFalse($repo->latest($project, SerpDevice::Desktop)['running shoes']->aiOverview);

        $this->db->execute('DELETE FROM project_keywords WHERE id = ?', [$keyword]);
        self::assertSame([], $repo->latest($project, SerpDevice::Desktop), 'Rows go with their keyword.');
    }

    public function testNewResearchKindsAreStored(): void
    {
        [$project, $user, $workspace] = $this->project();
        $repo = $this->app->make(ResearchRepository::class);
        foreach (['suggestions' => [['k' => 'best running shoes', 'v' => 9000, 's' => ['ai_overview'], 'r' => 120]], 'serp_strength' => [['d' => 'big.test', 'r' => 88]], 'seed_ideas' => [['seeds' => ['running shoes'], 'modifiers' => ['best'], 'notes' => '']]] as $kind => $rows) {
            $repo->queue($project, $workspace, $kind, '', ['location' => 2840, 'language' => 'en'], $user);
            $repo->complete($project, $kind, '', $rows);
        }
        self::assertSame(['ai_overview'], $repo->find($project, 'suggestions')?->keywords()[0]->serpTypes);
        self::assertSame(120, $repo->find($project, 'suggestions')?->keywords()[0]->avgReferringDomains);
        self::assertSame(['big.test' => 88], $repo->find($project, 'serp_strength')?->strengths());
        self::assertSame(['running shoes'], $repo->find($project, 'seed_ideas')?->seedIdeas()['seeds']);
    }

    /** @return array{0: int, 1: int, 2: int} project, user, workspace */
    private function project(): array
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000131', ?, 'Shop', 'www.shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$owner['workspace']->id],
        );

        return [$this->db->lastInsertId(), $owner['user']->id, $owner['workspace']->id];
    }
}

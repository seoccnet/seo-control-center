<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Rankings\AiOverview;
use SeoControlCenter\Domain\Rankings\AiOverviewReference;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\AiVisibility\AiOverviewKeyword;
use SeoControlCenter\Modules\AiVisibility\AiOverviewRepository;

/**
 * AI Overview history and sources against MySQL/MariaDB.
 */
final class AiOverviewPersistenceTest extends FullStackTestCase
{
    public function testHistorySourcesAndTopDomains(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000031', ?, 'Shop', 'www.shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$acme['workspace']->id],
        );
        $project = $this->db->lastInsertId();
        $keyword = function (string $text, string $publicId) use ($project): int {
            $this->db->execute(
                "INSERT INTO project_keywords (public_id, project_id, keyword, keyword_hash, location_code, language_code, created_at)
                 VALUES (?, ?, ?, ?, 2840, 'en', UTC_TIMESTAMP())",
                [$publicId, $project, $text, hash('sha256', $text, true)],
            );

            return $this->db->lastInsertId();
        };
        $red = $keyword('red shoes', '0192f0a0-0000-7000-8000-000000000041');
        $blue = $keyword('blue shoes', '0192f0a0-0000-7000-8000-000000000042');
        $this->db->execute(
            "INSERT INTO ranking_snapshots (project_keyword_id, project_id, device, checked_on, position, checked_at) VALUES (?, ?, 'desktop', '2026-10-03', 7, UTC_TIMESTAMP())",
            [$red, $project],
        );
        $repo = $this->app->make(AiOverviewRepository::class);
        self::assertSame('shop.test', $repo->projectDomain($project));

        $overview = new AiOverview([
            new AiOverviewReference('https://guide.test/a', 'guide.test', 'Guide'),
            new AiOverviewReference('https://shop.test/red', 'shop.test', null),
        ]);
        $repo->record($project, $red, SerpDevice::Desktop, '2026-09-26', null, null, null);
        $repo->record($project, $red, SerpDevice::Desktop, '2026-10-03', $overview, 2, 'https://shop.test/red');
        $repo->record($project, $blue, SerpDevice::Desktop, '2026-10-03', new AiOverview([new AiOverviewReference('https://guide.test/b', 'guide.test', null)]), null, null);
        // A re-check of the same day replaces the row and the sources.
        $repo->record($project, $blue, SerpDevice::Desktop, '2026-10-03', new AiOverview([new AiOverviewReference('https://guide.test/c', 'guide.test', null)]), null, null);

        self::assertSame('2026-10-03', $repo->latestDate($project, SerpDevice::Desktop));
        self::assertNull($repo->latestDate($project, SerpDevice::Mobile));
        self::assertSame('2026-09-26', $repo->dateOnOrBefore($project, SerpDevice::Desktop, '2026-09-30'));
        self::assertSame(['keywords' => 2, 'present' => 2, 'cited' => 1], $repo->summary($project, SerpDevice::Desktop, '2026-10-03'));
        self::assertSame(['keywords' => 1, 'present' => 0, 'cited' => 0], $repo->summary($project, SerpDevice::Desktop, '2026-09-26'));

        $rows = $repo->keywords($project, SerpDevice::Desktop, '2026-10-03', true, 10, 0);
        self::assertSame(['red shoes', 'blue shoes'], array_map(static fn(AiOverviewKeyword $k): string => $k->keyword, $rows));
        self::assertSame(7, $rows[0]->organicPosition);
        self::assertSame(2, $rows[0]->ownPosition);
        self::assertSame(['guide.test', 'shop.test'], $rows[0]->domains);
        self::assertNull($rows[1]->organicPosition);
        self::assertSame(2, $repo->countKeywords($project, SerpDevice::Desktop, '2026-10-03', true));
        self::assertSame(0, $repo->countKeywords($project, SerpDevice::Desktop, '2026-09-26', true));
        self::assertSame([['domain' => 'guide.test', 'keywords' => 2], ['domain' => 'shop.test', 'keywords' => 1]], $repo->topDomains($project, SerpDevice::Desktop, 10));
        self::assertSame(3, (int) $this->db->fetchValue('SELECT COUNT(*) FROM ai_overview_references WHERE project_id = ?', [$project]));

        $this->db->execute('DELETE FROM project_keywords WHERE id = ?', [$red]);
        self::assertSame(['keywords' => 1, 'present' => 1, 'cited' => 0], $repo->summary($project, SerpDevice::Desktop, '2026-10-03'));
    }
}

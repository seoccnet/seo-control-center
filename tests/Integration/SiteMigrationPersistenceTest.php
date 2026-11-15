<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\SiteMigration\RedirectCheck;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Modules\SiteAudit\Crawler\HtmlPage;
use SeoControlCenter\Modules\SiteAudit\PageResult;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;
use SeoControlCenter\Modules\SiteMigration\MigrationUrl;
use SeoControlCenter\Modules\SiteMigration\SiteMigrationRepository;

/** Site migration plans and the hreflang/structured data audit columns (1.2.0) on the real engine. */
final class SiteMigrationPersistenceTest extends FullStackTestCase
{
    public function testPlansAddressesSuggestionsAndTests(): void
    {
        $owner = $this->createUser('move@migration.test', 'Move');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('move@migration.test'), 'Move', 'move.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $repo = $this->app->make(SiteMigrationRepository::class);

        $plan = $repo->create($projectId, $owner['workspace']->id, 'Move', 'https://move.test', 'https://new.move.test', '2026-12-01', $owner['user']->id);
        self::assertSame($plan->id, $repo->find($projectId, $plan->publicId)?->id);
        self::assertNull($repo->find($projectId + 1, $plan->publicId), 'A plan is only found through its project.');

        self::assertSame(2, $repo->addUrls($plan->id, [['url' => 'https://move.test/a', 'clicks' => null], ['url' => 'https://move.test/b', 'clicks' => 3], ['url' => 'https://move.test/c', 'clicks' => 1]], 2));
        self::assertSame(0, $repo->addUrls($plan->id, [['url' => 'https://move.test/a', 'clicks' => 9]], 2), 'Known addresses take new clicks but are not added twice.');
        $rows = $repo->urls($plan->id, null, 0, 10);
        self::assertSame(['https://move.test/a', 'https://move.test/b'], array_map(static fn(MigrationUrl $u): string => $u->oldUrl, $rows));
        self::assertSame(9, $rows[0]->oldClicks);
        self::assertSame(MigrationUrl::key('https://move.test/a'), $rows[0]->key);

        $repo->replaceTargets($plan->id, ['https://new.move.test/a', 'https://new.move.test/a', 'https://new.move.test/z']);
        self::assertSame(['https://new.move.test/a', 'https://new.move.test/z'], $repo->targets($plan->id));
        self::assertSame(2, $repo->byId($plan->id)?->targetCount);

        [$a, $b] = $repo->urlsAfter($plan->id, 0, 10);
        self::assertTrue($repo->setTarget($plan->id, $b->key, 'https://new.move.test/z'));
        $repo->saveTest($plan->id, $a->id, RedirectCheck::OK, '301 → x', '2026-11-15 10:00:00');
        $repo->saveSuggestions($plan->id, [$a->id => ['url' => 'https://new.move.test/a', 'method' => 'path', 'confidence' => 100], $b->id => ['url' => 'https://new.move.test/a', 'method' => 'slug', 'confidence' => 85]]);
        $byKey = [];
        foreach ($repo->urlsAfter($plan->id, 0, 10) as $row) {
            $byKey[$row->oldUrl] = $row;
        }
        self::assertSame('https://new.move.test/a', $byKey['https://move.test/a']->targetUrl);
        self::assertSame(RedirectCheck::UNTESTED, $byKey['https://move.test/a']->testState, 'A new target makes the old test stale.');
        self::assertSame('https://new.move.test/z', $byKey['https://move.test/b']->targetUrl, 'A manual target survives suggestions.');
        self::assertSame('https://new.move.test/a', $byKey['https://move.test/b']->suggestedUrl);
        self::assertFalse($repo->setTarget($plan->id, str_repeat('0', 40), null));
        self::assertFalse($repo->setTarget($plan->id, "' OR 1=1 --", null));

        $repo->saveTest($plan->id, $a->id, RedirectCheck::WRONG_TARGET, 'elsewhere', '2026-11-15 10:00:00');
        self::assertSame(1, $repo->countUrls($plan->id, 'problems'));
        self::assertSame(0, $repo->countUrls($plan->id, 'unmatched'));
        $counts = $repo->stateCounts($plan->id);
        ksort($counts);
        self::assertSame([RedirectCheck::UNTESTED => 1, RedirectCheck::WRONG_TARGET => 1], $counts);

        $repo->setState($plan->id, 'test', 'running');
        $repo->saveTestCursor($plan->id, $b->id, '2026-11-15 11:00:00');
        $saved = $repo->byId($plan->id);
        self::assertSame('running', $saved?->testState);
        self::assertSame($b->id, $saved?->testCursor);
        self::assertSame('2026-11-15 11:00:00', $saved?->testedAt);

        $repo->delete($plan->id);
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_migration_urls'));
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_migration_targets'));
    }

    public function testAuditPagesKeepHreflangAndStructuredDataProblems(): void
    {
        $owner = $this->createUser('hreflang@migration.test', 'Hreflang');
        self::assertNotNull($owner['workspace']);
        $publicId = $this->createProject($this->browserFor('hreflang@migration.test'), 'Hreflang', 'hreflang.test');
        $projectId = (int) $this->db->fetchValue('SELECT id FROM projects WHERE public_id = ?', [$publicId]);
        $audits = $this->app->make(SiteAuditRepository::class);
        $audit = $audits->create($projectId, $owner['workspace']->id, 'https://hreflang.test/', 10, 'manual', $owner['user']->id);
        $audits->markRunning($audit->id);
        $audit = $audits->byId($audit->id) ?? self::fail('Audit not saved.');
        $page = $audits->pendingPages($audit->id, 1)[0];
        $html = HtmlPage::parse(
            '<html lang="de"><head><link rel="alternate" hreflang="de" href="/"><link rel="alternate" hreflang="en-uk" href="/en/">'
            . '<script type="application/ld+json">{"@context":"https://schema.org","@type":"Product","name":"Hose"}</script></head><body><h1>Hose</h1></body></html>',
            'https://hreflang.test/',
        );
        $audits->savePage($audit, $page, new PageResult('done', 200, 'text/html', page: $html));

        $stored = $audits->findPage($audit->id, 'https://hreflang.test/') ?? self::fail('Page not saved.');
        self::assertSame([['lang' => 'de', 'url' => 'https://hreflang.test/'], ['lang' => 'en-uk', 'url' => 'https://hreflang.test/en/']], $stored->hreflang);
        self::assertSame('de', $stored->htmlLang);
        self::assertContains('Needs offers, review or aggregateRating', array_column($stored->schemaProblems ?? [], 'problem'));
        self::assertInstanceOf(AuditPage::class, $stored);
    }
}

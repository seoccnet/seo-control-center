<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use Psr\Log\NullLogger;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Support\SystemClock;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\SearchConsole\Persistence\PdoSearchPerformanceReader;
use SeoControlCenter\Modules\SearchConsole\Persistence\PdoSearchPerformanceStore;

/**
 * TESTING.md -> Upgrades: a database created by any released version
 * upgrades to exactly the schema of a fresh installation, and data written
 * by an older version keeps working after the upgrade.
 */
final class UpgradePathTest extends DatabaseTestCase
{
    /**
     * Last migration shipped with each release (from version.json history).
     * Add a line for every release that ships a migration.
     */
    private const array RELEASES = [
        '0.1.0' => '2026_09_26_000010_create_license_state_table',
        '0.2.0' => '2026_09_26_000013_create_dataforseo_tables',
        '0.3.0' => '2026_09_26_000014_create_keyword_tables',
        '0.4.0' => '2026_09_26_000015_create_ranking_tables',
        '0.5.0' => '2026_09_26_000016_create_competitor_tables',
        '0.6.0' => '2026_09_26_000017_create_opportunity_tables',
        '0.7.0' => '2026_09_26_000018_create_mcp_tokens_table',
        '0.8.0' => '2026_09_27_000019_create_update_history_table',
        '0.8.3' => '2026_09_27_000020_create_search_console_monthly_rollups',
        '0.9.33' => '2026_10_04_000046_create_local_locations',
        '0.9.40' => '2026_10_05_000052_create_alert_webhooks',
        '0.9.41' => '2026_10_05_000053_create_seo_annotations',
        '0.9.42' => '2026_10_05_000054_create_brand_mention_tracking',
        '0.9.43' => '2026_10_05_000055_create_content_briefs',
        '0.9.45' => '2026_10_05_000056_create_google_inspections',
        '0.9.46' => '2026_10_05_000057_create_opportunity_tasks',
        '0.9.59' => '2026_10_07_000058_create_page_monitoring',
    ];

    private string $migrations;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrations = dirname(__DIR__, 2) . '/database/migrations';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            array_map('unlink', glob($dir . '/*.php') ?: []);
            @rmdir($dir);
        }
        parent::tearDown();
    }

    public function testEveryReleaseUpgradesToTheFreshInstallSchema(): void
    {
        foreach (self::RELEASES as $release => $last) {
            self::assertFileExists($this->migrations . '/' . $last . '.php', "Release $release: migration file missing (migrations must never be renamed or deleted).");
        }

        (new Migrator($this->db, [$this->migrations], new NullLogger()))->migrate();
        $fresh = $this->schema();

        foreach (self::RELEASES as $release => $last) {
            $this->dropEverything();
            (new Migrator($this->db, [$this->migrationsUpTo($last)], new NullLogger()))->migrate();
            $migrator = new Migrator($this->db, [$this->migrations], new NullLogger());
            $migrator->migrate();

            self::assertSame([], $migrator->pending(), "Upgrade from $release left migrations pending.");
            foreach ($migrator->status() as $status) {
                self::assertFalse($status->modifiedAfterApply, "Upgrade from $release: {$status->name} changed after it was applied.");
            }
            self::assertSame($fresh, $this->schema(), "Upgrade from $release produces a different schema than a fresh install.");
        }
    }

    public function testSearchDataFromVersion07KeepsWorkingAfterTheUpgrade(): void
    {
        (new Migrator($this->db, [$this->migrationsUpTo(self::RELEASES['0.7.0'])], new NullLogger()))->migrate();
        $project = $this->seedProjectAt07();
        $before = [
            'daily' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM search_console_daily'),
            'queries' => (int) $this->db->fetchValue('SELECT COUNT(*) FROM search_console_queries'),
            'top' => $this->db->fetchAll(
                'SELECT q.query, SUM(clicks) AS clicks FROM search_console_queries s JOIN search_queries q ON q.id = s.query_id
                  WHERE s.project_id = ? GROUP BY q.query ORDER BY clicks DESC, q.query LIMIT 5',
                [$project],
            ),
        ];

        (new Migrator($this->db, [$this->migrations], new NullLogger()))->migrate();

        self::assertSame($before['daily'], (int) $this->db->fetchValue('SELECT COUNT(*) FROM search_console_daily'));
        self::assertSame($before['queries'], (int) $this->db->fetchValue('SELECT COUNT(*) FROM search_console_queries'));
        $reader = new PdoSearchPerformanceReader($this->db);
        $range = new DateRange('2026-06-01', '2026-08-31');
        $expected = array_map(static fn(array $r): array => [(string) $r['query'], (int) $r['clicks']], $before['top']);
        $top = static fn(): array => array_map(static fn(DimensionRow $r): array => [$r->key, $r->metrics->clicks], $reader->top($project, PerformanceDimension::Query, $range, 5));

        self::assertSame($expected, $top(), 'Before any rollup exists, the daily rows are read.');
        $store = new PdoSearchPerformanceStore($this->db, new SystemClock());
        self::assertSame(3, $store->refreshRollups($project, static fn(): bool => true), 'June, July and August are rolled up after the upgrade.');
        self::assertSame($expected, $top(), 'Rollups give the same answer.');
    }

    public function testGa4SnapshotAndEmptyImportedDaysSurviveTheLatestReleasedUpgrade(): void
    {
        (new Migrator($this->db, [$this->migrationsUpTo(self::RELEASES['0.9.33'])], new NullLogger()))->migrate();
        $project = $this->seedProjectAt07();
        $this->db->execute("INSERT INTO ga4_project_properties (project_id, property_id, period_from, period_to, currency, timezone, limited, thresholded, fetched_at) VALUES (?, '123', '2026-09-01', '2026-09-28', 'USD', 'UTC', 0, 0, UTC_TIMESTAMP())", [$project]);
        $url = 'https://shop.acme.test/pricing';
        $this->db->execute('INSERT INTO ga4_landing_pages (project_id, date, url_hash, url, sessions, conversions, revenue) VALUES (?, ?, UNHEX(?), ?, 20, 2.5, 100)', [$project, '2026-09-01', hash('sha256', $url), $url]);
        $migrator = new Migrator($this->db, [$this->migrations], new NullLogger());
        $applied = $migrator->migrate();
        self::assertContains('2026_10_08_000062_create_forecast_snapshots', $applied, 'The upgrade includes forecast storage without changing the GA4 snapshot.');
        self::assertSame(28, (int) $this->db->fetchValue('SELECT COUNT(*) FROM ga4_import_days WHERE project_id = ?', [$project]), 'Successful empty snapshot dates become coverage markers too.');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM ga4_landing_pages WHERE project_id = ?', [$project]));
        self::assertSame(100.0, (float) $this->db->fetchValue('SELECT SUM(revenue) FROM ga4_landing_pages WHERE project_id = ?', [$project]));
        self::assertSame([], $migrator->migrate(), 'The second migration run is a no-op.');
    }

    /**
     * A project with three months of Search Console rows, written the way 0.7 wrote them.
     */
    private function seedProjectAt07(): int
    {
        $this->db->execute("INSERT INTO workspaces (public_id, name, created_at, updated_at)
            VALUES ('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6a02', 'Acme', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $workspaceId = (int) $this->db->fetchValue('SELECT id FROM workspaces');
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
            VALUES ('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6a03', ?, 'Shop', 'shop.acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$workspaceId]);
        $project = (int) $this->db->fetchValue('SELECT id FROM projects');

        foreach (['seeds', 'triploid seeds', 'sunflower seeds', 'organic seeds'] as $i => $query) {
            $this->db->execute('INSERT INTO search_queries (id, project_id, query_hash, query) VALUES (?, ?, UNHEX(?), ?)', [$i + 1, $project, hash('sha256', $query), $query]);
        }
        $day = new \DateTimeImmutable('2026-06-01');
        for ($d = 0; $d < 92; ++$d, $day = $day->modify('+1 day')) {
            $date = $day->format('Y-m-d');
            $this->db->execute('INSERT INTO search_console_daily (project_id, date, clicks, impressions, position) VALUES (?, ?, 40, 900, 7.5)', [$project, $date]);
            $this->db->execute('INSERT INTO search_console_import_days (project_id, date, imported_at) VALUES (?, ?, UTC_TIMESTAMP())', [$project, $date]);
            for ($q = 1; $q <= 4; ++$q) {
                $this->db->execute(
                    'INSERT INTO search_console_queries (project_id, date, query_id, clicks, impressions, position) VALUES (?, ?, ?, ?, ?, ?)',
                    [$project, $date, $q, ($d * $q) % 9, 50 + $q * 10, 3 + $q / 2],
                );
            }
        }

        return $project;
    }

    /**
     * A directory with the migrations up to and including $last.
     */
    private function migrationsUpTo(string $last): string
    {
        $dir = sys_get_temp_dir() . '/scc-upgrade-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o700, true);
        $this->tempDirs[] = $dir;
        foreach (glob($this->migrations . '/*.php') ?: [] as $file) {
            if (strcmp(basename($file, '.php'), $last) <= 0) {
                copy($file, $dir . '/' . basename($file));
            }
        }

        return $dir;
    }

    /**
     * @return array<string, string> table => normalized CREATE TABLE statement
     */
    private function schema(): array
    {
        $schema = [];
        foreach ($this->db->fetchAll('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME') as $table) {
            $row = $this->db->fetchOne(sprintf('SHOW CREATE TABLE `%s`', (string) $table['name']));
            self::assertNotNull($row);
            $schema[(string) $table['name']] = (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) ($row['Create Table'] ?? ''));
        }

        return $schema;
    }

    private function dropEverything(): void
    {
        $this->db->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->db->fetchAll('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $table) {
            $this->db->statement(sprintf('DROP TABLE `%s`', (string) $table['name']));
        }
        $this->db->statement('SET FOREIGN_KEY_CHECKS = 1');
    }
}

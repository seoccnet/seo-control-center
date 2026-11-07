<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Crypto\SecretRotator;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\ProviderResponseStore;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * DataForSEO credentials, usage ledger and raw responses against MySQL/MariaDB.
 */
final class DataForSeoPersistenceTest extends FullStackTestCase
{
    public function testPasswordIsEncryptedWorkspaceBoundAndRotatable(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        self::assertNotNull($rival['workspace']);
        $repo = $this->app->make(DataForSeoProfileRepository::class);

        $repo->save($acme['workspace']->id, new DataForSeoCredentials('api@acme.test', 'dfs-secret-password'), 42.5, $acme['user']->id);

        $raw = (string) $this->db->fetchValue('SELECT password FROM dataforseo_profiles WHERE workspace_id = ?', [$acme['workspace']->id]);
        self::assertStringNotContainsString('dfs-secret-password', $raw);
        self::assertSame('dfs-secret-password', $repo->credentials($acme['workspace']->id)?->password);
        self::assertSame(42.5, $repo->find($acme['workspace']->id)?->balance);
        self::assertNull($repo->find($rival['workspace']->id));

        // A password envelope copied to another workspace's row does not decrypt (AAD binding).
        $repo->save($rival['workspace']->id, new DataForSeoCredentials('api@rival.test', 'other'), null, $rival['user']->id);
        $this->db->execute('UPDATE dataforseo_profiles SET password = ? WHERE workspace_id = ?', [$raw, $rival['workspace']->id]);
        try {
            $repo->credentials($rival['workspace']->id);
            self::fail('A copied envelope must not decrypt.');
        } catch (\Throwable) {
        }

        $repo->setError($acme['workspace']->id, 'rejected');
        self::assertSame('error', $repo->find($acme['workspace']->id)?->status);
        $repo->recordCheck($acme['workspace']->id, 40.0);
        self::assertTrue($repo->find($acme['workspace']->id)?->isActive());

        $counts = $this->app->make(SecretRotator::class)->countByKey();
        self::assertSame(2, $counts[1] ?? 0, 'Both passwords are registered for key rotation.');

        $repo->delete($acme['workspace']->id);
        self::assertNull($repo->credentials($acme['workspace']->id));
    }

    public function testLedgerEnforcesBudgetsAndAccountsCosts(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        self::assertNotNull($rival['workspace']);
        $ws = $acme['workspace']->id;
        $ledger = $this->app->make(UsageLedger::class);

        self::assertNull($ledger->budget($ws, 'dataforseo')->dailyLimit);
        $ledger->saveBudget($ws, 'dataforseo', new ApiBudget(1.0, 10.0, 0.5), $acme['user']->id);
        self::assertSame(0.5, $ledger->budget($ws, 'dataforseo')->operationLimit);

        $first = $ledger->reserve($ws, null, 'dataforseo', 'keyword_metrics', 0.4);
        self::assertEqualsWithDelta(0.4, $ledger->summary($ws, 'dataforseo')->today, 0.000001, 'Open reservations count as spent.');
        $ledger->settle($first, 0.3, 'task-1');
        $ledger->settle($first, 99.0); // settling twice has no effect

        $second = $ledger->reserve($ws, null, 'dataforseo', 'keyword_metrics', 0.4);
        $ledger->release($second);

        $third = $ledger->reserve($ws, null, 'dataforseo', 'serp', 0.45);
        $summary = $ledger->summary($ws, 'dataforseo');
        self::assertEqualsWithDelta(0.75, $summary->today, 0.000001);
        self::assertSame(2, $summary->requestsToday, 'Released reservations are not counted.');

        try {
            $ledger->reserve($ws, null, 'dataforseo', 'serp', 0.3);
            self::fail('The daily budget must stop this reservation.');
        } catch (BudgetExceededException) {
        }
        try {
            $ledger->reserve($ws, null, 'dataforseo', 'serp', 0.6);
            self::fail('The per-request limit must stop this reservation.');
        } catch (BudgetExceededException) {
        }

        // Other workspaces and providers have their own budgets.
        $ledger->reserve($rival['workspace']->id, null, 'dataforseo', 'serp', 5.0);
        self::assertEqualsWithDelta(0.75, $ledger->summary($ws, 'dataforseo')->today, 0.000001);

        // Daily spending per operation: settled cost, open estimate, no released requests or other workspaces.
        $this->db->execute("INSERT INTO api_usage (workspace_id, provider, operation, status, estimated_cost, cost, created_at) VALUES (?, 'dataforseo', 'serp', 'charged', 1.0, 0.8, '2020-01-05 10:00:00')", [$ws]);
        $today = gmdate('Y-m-d');
        self::assertSame([
            ['date' => '2020-01-05', 'operation' => 'serp', 'cost' => 0.8, 'requests' => 1],
            ['date' => $today, 'operation' => 'keyword_metrics', 'cost' => 0.3, 'requests' => 1],
            ['date' => $today, 'operation' => 'serp', 'cost' => 0.45, 'requests' => 1],
        ], $ledger->dailySpend($ws, 'dataforseo', '2020-01-01'));
        self::assertCount(2, $ledger->dailySpend($ws, 'dataforseo', $today));

        // A reservation left open by a crashed worker is closed at its estimate.
        $this->db->execute('UPDATE api_usage SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 HOUR) WHERE id = ?', [$third->id]);
        self::assertSame(1, $ledger->closeStale(new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'))));
        self::assertSame('charged', $this->db->fetchValue('SELECT status FROM api_usage WHERE id = ?', [$third->id]));
        self::assertSame('task-1', $this->db->fetchValue('SELECT provider_task_id FROM api_usage WHERE id = ?', [$first->id]));
    }

    public function testRawResponsesAreCompressedAndPurgedAfterExpiry(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $store = $this->app->make(ProviderResponseStore::class);
        $body = str_repeat('{"keyword":"shoes","search_volume":1000},', 200);

        $store->store($acme['workspace']->id, null, 'dataforseo', 'keyword_metrics', 200, $body, new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')));
        $store->store($acme['workspace']->id, null, 'dataforseo', 'keyword_metrics', 200, $body, new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC')));

        $stored = (string) $this->db->fetchValue('SELECT body FROM provider_responses ORDER BY id LIMIT 1');
        self::assertLessThan(strlen($body), strlen($stored));
        self::assertSame($body, gzdecode($stored));

        self::assertSame(1, $store->purgeExpired(new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM provider_responses'));
    }
}

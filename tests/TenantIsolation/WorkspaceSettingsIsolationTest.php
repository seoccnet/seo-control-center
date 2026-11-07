<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Workspace settings act on the current workspace only; a forged workspace
 * field never reaches another tenant.
 */
final class WorkspaceSettingsIsolationTest extends TestCase
{
    public function testRenameAndDeleteNeverReachAnotherWorkspace(): void
    {
        $s = new Scenario();
        $rival = $s->loggedIn($s->rivalOwner);
        $rival->get('/workspace/settings');

        $rival->post('/workspace/settings', ['name' => 'Renamed', 'workspace' => $s->acme->publicId, 'workspace_id' => (string) $s->acme->id]);
        self::assertSame('Acme', $s->dir->workspaces[$s->acme->id]->name);
        self::assertSame('Renamed', $s->dir->workspaces[$s->rival->id]->name);

        $rival->get('/workspace/settings');
        $rival->post('/workspace/delete', ['confirm' => 'Acme', 'workspace' => $s->acme->publicId]);
        self::assertArrayNotHasKey($s->acme->id, $s->dir->deletedWorkspaces);
        self::assertStringNotContainsString('Acme', (string) $rival->get('/workspace/settings')->getBody());
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * TESTING.md -> Tenant isolation (mandatory): attempt to access Workspace B
 * resources while authenticated as Workspace A, knowing B's public IDs.
 *
 * Foreign and invisible projects must answer 404 (not 403) so that their
 * existence is not revealed, and nothing may change.
 */
final class CrossWorkspaceAccessTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function projectRoutes(): iterable
    {
        yield 'show' => ['GET', ''];
        yield 'edit form' => ['GET', '/edit'];
        yield 'update' => ['POST', ''];
        yield 'delete' => ['POST', '/delete'];
        yield 'keywords export' => ['GET', '/keywords/export'];
        yield 'rankings export' => ['GET', '/rankings/export'];
        yield 'opportunities export' => ['GET', '/opportunities/export'];
        yield 'performance export' => ['GET', '/performance/export'];
        yield 'bing link page' => ['GET', '/bing'];
        yield 'bing link' => ['POST', '/bing'];
        yield 'bing unlink' => ['POST', '/bing/unlink'];
        yield 'bing performance' => ['GET', '/performance/bing'];
        yield 'bing import' => ['POST', '/performance/bing/import'];
        yield 'bing export' => ['GET', '/performance/bing/export'];
        yield 'indexnow page' => ['GET', '/indexnow'];
        yield 'indexnow setup' => ['POST', '/indexnow/setup'];
        yield 'indexnow verify' => ['POST', '/indexnow/verify'];
        yield 'indexnow sitemap' => ['POST', '/indexnow/sitemap'];
        yield 'indexnow submit' => ['POST', '/indexnow/submit'];
        yield 'indexnow key file' => ['GET', '/indexnow/key-file'];
        yield 'reports page' => ['GET', '/reports'];
        yield 'reports schedule' => ['POST', '/reports'];
        yield 'reports test' => ['POST', '/reports/test'];
        yield 'report view' => ['GET', '/reports/view'];
        yield 'report pdf' => ['GET', '/reports/pdf'];
        yield 'ai visibility' => ['GET', '/ai-visibility'];
        yield 'ai visibility weekly' => ['POST', '/ai-visibility/weekly'];
        yield 'local page' => ['GET', '/local'];
        yield 'local settings' => ['POST', '/local/settings'];
        yield 'local keywords' => ['POST', '/local/keywords'];
        yield 'local keyword delete' => ['POST', '/local/keywords/delete'];
        yield 'local check' => ['POST', '/local/check'];
        yield 'local grid' => ['POST', '/local/grid'];
        yield 'backlinks page' => ['GET', '/backlinks'];
        yield 'backlinks fetch' => ['POST', '/backlinks/fetch'];
        yield 'backlinks monthly' => ['POST', '/backlinks/monthly'];
        yield 'keyword research' => ['GET', '/keywords/research'];
        yield 'keyword research fetch' => ['POST', '/keywords/research/fetch'];
        yield 'keyword research track' => ['POST', '/keywords/research/track'];
        yield 'site audit page' => ['GET', '/site-audit'];
        yield 'site audit start' => ['POST', '/site-audit'];
        yield 'site audit settings' => ['POST', '/site-audit/settings'];
    }

    #[DataProvider('projectRoutes')]
    public function testOwnerCannotReachAnotherWorkspacesProject(string $method, string $suffix): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/');
        $url = '/projects/' . $s->rivalProject->publicId . $suffix;

        $response = $method === 'GET'
            ? $browser->get($url)
            : $browser->post($url, ['name' => 'Hacked', 'primary_domain' => 'hacked.test', 'confirm_name' => 'Rival Site']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Rival Site', $s->dir->projects[$s->rivalProject->id]->name);
        self::assertArrayNotHasKey($s->rivalProject->id, $s->dir->deleted);
        self::assertStringNotContainsString('Rival Site', (string) $response->getBody());
    }

    #[DataProvider('projectRoutes')]
    public function testUnassignedMemberCannotReachProjectInOwnWorkspace(string $method, string $suffix): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->unassigned);
        $browser->get('/');
        $url = '/projects/' . $s->acmeProject->publicId . $suffix;

        $response = $method === 'GET'
            ? $browser->get($url)
            : $browser->post($url, ['name' => 'Hacked', 'primary_domain' => 'hacked.test', 'confirm_name' => 'Acme Shop']);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Acme Shop', $s->dir->projects[$s->acmeProject->id]->name);
    }

    public function testCannotSwitchIntoForeignWorkspace(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/');

        $response = $browser->post('/workspaces/switch', ['workspace' => $s->rival->publicId]);

        self::assertSame(404, $response->getStatusCode());
        $html = (string) $browser->get('/projects')->getBody();
        self::assertStringContainsString('Acme Shop', $html);
        self::assertStringNotContainsString('Rival Site', $html);
    }

    public function testCreatedProjectsAlwaysLandInTheCurrentWorkspace(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        $browser->get('/projects/new');

        // A forged workspace field must be ignored.
        $browser->post('/projects', ['name' => 'Injected', 'primary_domain' => 'inj.test', 'workspace_id' => (string) $s->rival->id, 'workspace' => $s->rival->publicId]);

        $created = array_values(array_filter($s->dir->projects, static fn($p): bool => $p->name === 'Injected'));
        self::assertCount(1, $created);
        self::assertSame($s->acme->id, $created[0]->workspaceId);
    }

    public function testWorkspaceSelectionIsRevalidatedEveryRequest(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        self::assertStringContainsString('Acme Shop', (string) $browser->get('/projects')->getBody());

        // Membership revoked while the session is active.
        unset($s->dir->workspaceRoles[$s->acme->id][$s->owner->id]);

        self::assertSame(403, $browser->get('/projects')->getStatusCode());
        self::assertSame(403, $browser->get('/projects/' . $s->acmeProject->publicId)->getStatusCode());
        self::assertStringNotContainsString('Acme Shop', (string) $browser->get('/')->getBody());
    }
}

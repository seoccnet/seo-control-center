<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Tests\Support\Scenario;

final class InterfaceHttpTest extends TestCase
{
    public function testIntegrationDirectoryGroupsCardsAndKeepsManagementPermissions(): void
    {
        $s = new Scenario();
        $owner = HTMLDocument::createFromString((string) $s->loggedIn($s->owner)->get('/integrations')->getBody(), LIBXML_NOERROR);
        self::assertCount(6, $owner->querySelectorAll('.integration-grid > .card'));
        self::assertCount(1, $owner->querySelectorAll('main h1'));
        $viewer = HTMLDocument::createFromString((string) $s->loggedIn($s->viewer)->get('/integrations')->getBody(), LIBXML_NOERROR);
        self::assertCount(4, $viewer->querySelectorAll('.integration-grid > .card'));
        self::assertCount(0, $viewer->querySelectorAll('form[action="/integrations/google/connect"]'));
    }

    public function testSetupGuidesAreReadableWithoutRunningProviderActions(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        foreach (['/integrations/bing', '/integrations/dataforseo', '/integrations/assistant', '/integrations/mcp', '/integrations/ga4', '/integrations/web-vitals', '/projects/' . $s->acmeProject->publicId . '/indexnow'] as $path) {
            $response = $browser->get($path);
            self::assertSame(200, $response->getStatusCode());
            $doc = HTMLDocument::createFromString((string) $response->getBody(), LIBXML_NOERROR);
            self::assertCount(1, $doc->querySelectorAll('details.setup-guide'), $path);
            self::assertCount(match ($path) {
                '/integrations/ga4' => 6,
                '/integrations/web-vitals' => 5,
                default => 4,
            }, $doc->querySelectorAll('.setup-steps > li'), $path);
            self::assertCount(0, $doc->querySelectorAll('.setup-guide form'), $path);
        }
        $root = $s->addUser('root@interface.test', SystemRole::Admin);
        $response = $s->loggedIn($root)->get('/system/integrations/google');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Administrator tutorial', (string) $response->getBody());
    }
}

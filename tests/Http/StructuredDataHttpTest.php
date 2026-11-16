<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/** The structured data generator (1.5.0). */
final class StructuredDataHttpTest extends TestCase
{
    public function testTypeIsSuggestedPrefilledAndGenerated(): void
    {
        $s = new Scenario();
        $audits = $s->container->get(InMemorySiteAudit::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        $audit = $audits->create($s->acmeProject->id, $s->acme->id, 'https://shop.acme.test/', 10, 'manual', null);
        $first = $audits->pendingPages($audit->id, 1)[0];
        $audits->pages[$audit->id] = [
            $first->id => new AuditPage($first->id, 'https://shop.acme.test/', 0, 'done', 200, 'text/html', title: 'Acme Shoes', description: 'Shoes since 1990', business: ['types' => [], 'name' => 'Acme GmbH', 'phones' => ['+49 30 123'], 'address' => null]),
            $first->id + 1 => new AuditPage($first->id + 1, 'https://shop.acme.test/shoes/red', 1, 'done', 200, 'text/html', title: 'Red shoe | Acme', h1: 'Red <shoe>', ogType: 'product', schemaTypes: ['BreadcrumbList']),
        ];
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $viewer = $s->loggedIn($s->viewer);
        $url = '/projects/' . $s->acmeProject->publicId . '/site-audit/schema';

        $home = (string) $viewer->get($url)->getBody();
        self::assertStringContainsString('<option value="Organization" selected>', $home, 'The home page gets Organization.');
        self::assertStringContainsString('value="Acme GmbH"', $home);
        self::assertStringContainsString('value="+49 30 123"', $home);

        $product = (string) $viewer->get($url . '?url=' . rawurlencode('https://shop.acme.test/shoes/red'))->getBody();
        self::assertStringContainsString('<option value="Product" selected>', $product);
        self::assertStringContainsString('value="Red &lt;shoe&gt;"', $product);
        self::assertStringContainsString('The page already has: BreadcrumbList.', $product);

        $generated = (string) $viewer->get($url . '?' . http_build_query(['url' => 'https://shop.acme.test/shoes/red', 'type' => 'Product', 'generate' => '1', 'f' => ['name' => 'Red </script><b>', 'price' => '49.90']]))->getBody();
        self::assertStringContainsString('&quot;@type&quot;: &quot;Product&quot;', $generated);
        self::assertStringContainsString('Red &lt;\/script&gt;&lt;b&gt;', $generated, 'A closing script tag cannot end the markup early.');
        self::assertStringContainsString('still empty: Image URL, Currency (e.g. EUR)', $generated);

        $foreign = (string) $viewer->get($url . '?url=' . rawurlencode('https://evil.test/'))->getBody();
        self::assertStringNotContainsString('evil.test', $foreign, 'Only the project site is used.');
        self::assertSame(404, $viewer->get('/projects/' . $s->rivalProject->publicId . '/site-audit/schema')->getStatusCode());
    }
}

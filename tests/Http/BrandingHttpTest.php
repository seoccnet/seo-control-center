<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Branding\Branding;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Branding\BrandingService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBranding;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * White label: settings (system admins only), the layout, the public logo
 * and stylesheet, and the logo upload rules.
 */
final class BrandingHttpTest extends TestCase
{
    private Scenario $s;

    private InMemoryBranding $branding;

    private string $dir;

    private User $root;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->root = $this->s->addUser('root@acme.test', SystemRole::Admin);
        $branding = $this->s->container->get(InMemoryBranding::class);
        self::assertInstanceOf(InMemoryBranding::class, $branding);
        $this->branding = $branding;
        $this->dir = sys_get_temp_dir() . '/scc-branding-' . bin2hex(random_bytes(4));
        $this->s->container->instance(BrandingService::class, $this->service());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testAdminsBrandTheAppAndOthersCannot(): void
    {
        // Without white label: Report a bug and the Discord community in the sidebar.
        $plain = (string) $this->s->loggedIn($this->s->member)->get('/projects')->getBody();
        self::assertStringContainsString('<span>Report a bug</span>', $plain);
        self::assertStringContainsString('/issues/new/choose', $plain);
        self::assertStringContainsString('<span>Source code</span>', $plain);
        self::assertStringContainsString('href="https://discord.gg/5pPRTCww2X" target="_blank" rel="noopener noreferrer"', $plain);
        self::assertSame(403, $this->s->loggedIn($this->s->owner)->get('/system/branding')->getStatusCode(), 'Workspace owners are not system admins.');
        self::assertSame(403, $this->s->loggedIn($this->s->admin)->get('/system/branding')->getStatusCode(), 'Nor are workspace admins.');

        $admin = $this->s->loggedIn($this->root);
        $admin->get('/system/branding');
        $invalid = $admin->post('/system/branding', ['product_name' => 'Agency <b>', 'accent_color' => 'teal', 'report_footer' => '']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Enter a colour like #2f5fd0.', (string) $invalid->getBody());
        $admin->get('/system/branding');

        self::assertSame(303, $admin->post('/system/branding', ['product_name' => 'Rank Radar', 'accent_color' => '#0F7B6C', 'report_footer' => 'Prepared by Radar Agency'])->getStatusCode());
        self::assertSame('#0f7b6c', $this->branding->get()->accentColor);
        self::assertContains('system.branding_saved', $this->s->audit()->actions());

        $page = (string) $this->s->loggedIn($this->s->member)->get('/')->getBody();
        self::assertStringContainsString('<span>Rank Radar</span>', $page, 'The sidebar shows the new name.');
        self::assertStringNotContainsString('discord.gg', $page, 'White label hides SEO Control Center\'s own support channels.');
        self::assertStringNotContainsString('Report a bug', $page);
        self::assertStringContainsString('· Rank Radar</title>', $page);
        self::assertStringContainsString('<link rel="stylesheet" href="/branding.css?v=0f7b6c">', $page);
        self::assertStringContainsString('Rank Radar', (string) $this->s->browser()->get('/login')->getBody(), 'The sign-in page too.');

        $css = $this->s->browser()->get('/branding.css?v=0f7b6c');
        self::assertSame(200, $css->getStatusCode(), 'Public: the sign-in page needs it.');
        self::assertStringStartsWith('text/css', $css->getHeaderLine('Content-Type'));
        self::assertStringContainsString('--accent: #0f7b6c;', (string) $css->getBody());
    }

    public function testLogoUploadIsCheckedServedAndRemoved(): void
    {
        $service = $this->service();
        $admin = $this->root;
        $png = $this->file($this->png(32, 32));

        $service->uploadLogo($admin, $png, (int) filesize($png), ClientInfo::cli());
        $logo = $this->branding->get();
        self::assertTrue($logo->hasLogo());
        $served = $this->s->browser()->get((string) $logo->logoUrl());
        self::assertSame(200, $served->getStatusCode());
        self::assertSame('image/png', $served->getHeaderLine('Content-Type'));
        self::assertSame((string) file_get_contents($png), (string) $served->getBody());
        self::assertStringContainsString('<img class="logo-mark brand-logo" src="' . $logo->logoUrl() . '"', (string) $this->s->loggedIn($this->s->member)->get('/')->getBody());

        foreach (['svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>', 'tiny' => $this->png(8, 8), 'text' => 'not an image'] as $name => $content) {
            try {
                $file = $this->file($content);
                $service->uploadLogo($admin, $file, (int) filesize($file), ClientInfo::cli());
                self::fail($name . ' must be refused.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('logo', $e->errors(), $name);
            }
        }
        self::assertSame($logo->logoVersion, $this->branding->get()->logoVersion, 'A refused upload keeps the old logo.');

        $service->removeLogo($admin, ClientInfo::cli());
        self::assertFalse($this->branding->get()->hasLogo());
        self::assertSame(404, $this->s->browser()->get((string) $logo->logoUrl())->getStatusCode());
        self::assertSame([], glob($this->dir . '/logo-*') ?: [], 'The file is deleted.');
    }

    public function testStylesheetDerivesReadableColours(): void
    {
        self::assertSame("/* default colours */\n", BrandingService::stylesheet(Branding::none()));
        $dark = BrandingService::stylesheet(new Branding(accentColor: '#0f7b6c'));
        self::assertStringContainsString('--accent-strong: #0c6559;', $dark);
        self::assertStringContainsString('--on-accent: #ffffff;', $dark);
        self::assertStringContainsString('--on-accent: #141a22;', BrandingService::stylesheet(new Branding(accentColor: '#ffd479')), 'Dark text on light accents.');
        self::assertSame('SEO Control Center', Branding::none()->name(''));
        self::assertSame('My SCC', Branding::none()->name('My SCC'), 'APP_NAME stays the fallback.');
    }

    private function service(): BrandingService
    {
        $authorizer = $this->s->container->get(Authorizer::class);
        $audit = $this->s->container->get(AuditLogger::class);
        self::assertInstanceOf(Authorizer::class, $authorizer);
        self::assertInstanceOf(AuditLogger::class, $audit);

        $current = $this->s->container->get(\SeoControlCenter\Core\Branding\CurrentBranding::class);
        self::assertInstanceOf(\SeoControlCenter\Core\Branding\CurrentBranding::class, $current);

        return new BrandingService($this->branding, $authorizer, $audit, $this->dir, $current);
    }

    private function file(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'scc-logo');
        file_put_contents($file, $content);

        return $file;
    }

    /**
     * A valid grey PNG without the GD extension.
     */
    private function png(int $width, int $height): string
    {
        $chunk = static fn(string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $rows = str_repeat("\x00" . str_repeat("\x80", $width), $height);

        return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 0, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress($rows)) . $chunk('IEND', '');
    }
}

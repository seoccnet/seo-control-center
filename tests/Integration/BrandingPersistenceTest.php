<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Branding\BrandingRepository;

/**
 * The single branding row against MySQL/MariaDB.
 */
final class BrandingPersistenceTest extends FullStackTestCase
{
    public function testSettingsAndLogoAreKeptApart(): void
    {
        $admin = $this->createUser('root@acme.test', 'Acme');
        $repo = $this->app->make(BrandingRepository::class);
        self::assertFalse($repo->get()->isCustomized());

        $repo->setLogo('logo-0123456789abcdef.png', '0123456789abcdef', $admin['user']->id);
        $repo->save('Rank Radar', '#0f7b6c', null, $admin['user']->id);
        $branding = $repo->get();
        self::assertSame(['Rank Radar', '#0f7b6c', null, '0123456789abcdef'], [$branding->productName, $branding->accentColor, $branding->reportFooter, $branding->logoVersion], 'Saving the texts keeps the logo.');

        $repo->setLogo(null, null, $admin['user']->id);
        self::assertFalse($repo->get()->hasLogo());
        self::assertSame('Rank Radar', $repo->get()->productName, 'Removing the logo keeps the texts.');
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM branding_settings'));
    }
}

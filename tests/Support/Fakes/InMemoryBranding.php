<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Branding\Branding;
use SeoControlCenter\Core\Branding\BrandingRepository;

final class InMemoryBranding implements BrandingRepository
{
    public Branding $branding;

    public function __construct()
    {
        $this->branding = Branding::none();
    }

    public function get(): Branding
    {
        return $this->branding;
    }

    public function save(?string $productName, ?string $accentColor, ?string $reportFooter, int $userId): void
    {
        $this->branding = new Branding($productName, $accentColor, $reportFooter, $this->branding->logoFile, $this->branding->logoVersion);
    }

    public function setLogo(?string $file, ?string $version, int $userId): void
    {
        $b = $this->branding;
        $this->branding = new Branding($b->productName, $b->accentColor, $b->reportFooter, $file, $version);
    }
}

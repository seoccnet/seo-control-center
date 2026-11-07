<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Branding;

/**
 * The single row of white-label settings (System → Branding).
 */
interface BrandingRepository
{
    public function get(): Branding;

    public function save(?string $productName, ?string $accentColor, ?string $reportFooter, int $userId): void;

    public function setLogo(?string $file, ?string $version, int $userId): void;
}

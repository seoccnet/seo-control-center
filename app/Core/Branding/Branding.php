<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Branding;

/**
 * The installation's white-label settings. Unset values fall back to the
 * product's own name, logo and colours.
 */
final readonly class Branding
{
    public const string DEFAULT_NAME = 'SEO Control Center';

    public function __construct(
        public ?string $productName = null,
        public ?string $accentColor = null,
        public ?string $reportFooter = null,
        public ?string $logoFile = null,
        public ?string $logoVersion = null,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    public function name(string $fallback = self::DEFAULT_NAME): string
    {
        return $this->productName ?? ($fallback !== '' ? $fallback : self::DEFAULT_NAME);
    }

    public function hasLogo(): bool
    {
        return $this->logoFile !== null && $this->logoVersion !== null;
    }

    public function logoUrl(): ?string
    {
        return $this->hasLogo() ? '/branding/logo?v=' . $this->logoVersion : null;
    }

    /**
     * The stylesheet with the accent colour, or null to keep the default colours.
     */
    public function stylesheetUrl(): ?string
    {
        return $this->accentColor !== null ? '/branding.css?v=' . substr($this->accentColor, 1) : null;
    }

    public function isCustomized(): bool
    {
        return $this->productName !== null || $this->accentColor !== null || $this->reportFooter !== null || $this->hasLogo();
    }
}

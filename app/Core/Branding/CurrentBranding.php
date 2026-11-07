<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Branding;

use Closure;

/**
 * The branding for this request, loaded once on first use. Falls back to
 * the defaults when it cannot be read (installer, database down, table not
 * migrated yet), so a page never fails because of its branding.
 */
final class CurrentBranding
{
    private ?Branding $branding = null;

    /**
     * @param Closure(): Branding $loader
     */
    public function __construct(private readonly Closure $loader) {}

    public function get(): Branding
    {
        if ($this->branding === null) {
            try {
                $this->branding = ($this->loader)();
            } catch (\Throwable) {
                $this->branding = Branding::none();
            }
        }

        return $this->branding;
    }

    /**
     * Reload on next use (after the settings changed).
     */
    public function forget(): void
    {
        $this->branding = null;
    }
}

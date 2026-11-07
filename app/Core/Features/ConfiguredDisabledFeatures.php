<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Features;

/** The features listed in DISABLED_FEATURES (config/features.php). */
final readonly class ConfiguredDisabledFeatures implements DisabledFeatures
{
    /**
     * @param list<string> $disabled
     */
    public function __construct(private array $disabled) {}

    public function disabled(): array
    {
        return $this->disabled;
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Features;

use SeoControlCenter\Core\Http\Exception\HttpException;

/**
 * The only place that answers "is this feature on?" (ADR-052). Every feature
 * is on unless the administrator lists it in DISABLED_FEATURES.
 */
final readonly class FeatureManager
{
    public function __construct(private DisabledFeatures $features) {}

    public function enabled(Feature $feature): bool
    {
        return !in_array($feature->value, $this->features->disabled(), true);
    }

    /**
     * Stops a user action that needs a feature that is off, and says that
     * existing data stays available.
     *
     * @throws HttpException 403
     */
    public function require(Feature $feature): void
    {
        if (!$this->enabled($feature)) {
            throw new HttpException(403, sprintf('%s is turned off on this installation. Existing data stays available.', $feature->label()));
        }
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * Fixed bands of the priority score (impact × confidence / 100 / effort) for
 * the colour scale from green (very low) to red (very high). The bands are
 * absolute, so a colour means the same on every page and filter. As a guide:
 * a low-effort finding worth about 1,000 extra clicks a month with good
 * confidence scores about 60, one worth about 100 clicks about 35.
 */
enum PriorityBand: int
{
    case VeryLow = 1;
    case Low = 2;
    case Medium = 3;
    case High = 4;
    case VeryHigh = 5;

    /** Lower bound of each band, highest first. */
    public const array THRESHOLDS = [5 => 45.0, 4 => 30.0, 3 => 18.0, 2 => 8.0, 1 => 0.0];

    public static function of(float $priority): self
    {
        foreach (self::THRESHOLDS as $band => $minimum) {
            if ($priority >= $minimum) {
                return self::from($band);
            }
        }

        return self::VeryLow;
    }

    /**
     * Band of an impact score alone (0-100), for estimates without a confidence or effort.
     */
    public static function ofImpact(int $impact): self
    {
        return match (true) {
            $impact >= 75 => self::VeryHigh,
            $impact >= 60 => self::High,
            $impact >= 45 => self::Medium,
            $impact >= 30 => self::Low,
            default => self::VeryLow,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::VeryHigh => 'Very high',
            self::High => 'High',
            self::Medium => 'Medium',
            self::Low => 'Low',
            self::VeryLow => 'Very low',
        };
    }

    public function cssClass(): string
    {
        return 'priority-band-' . $this->value;
    }

    /**
     * Score range for legends, e.g. "30–44".
     */
    public function range(): string
    {
        $minimum = self::THRESHOLDS[$this->value];

        return $this === self::VeryHigh ? sprintf('%d+', $minimum) : sprintf('%d–%d', $minimum, self::THRESHOLDS[$this->value + 1] - 1);
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

/**
 * Rank tracking settings of a project. Checks cost provider money, so
 * tracking is off until someone with analysis.execute switches it on.
 */
final readonly class RankingSettings
{
    public const string DAILY = 'daily';
    public const string WEEKLY = 'weekly';
    public const int DEPTH = 100;

    public function __construct(
        public bool $enabled,
        public SerpDevice $device,
        public string $frequency,
    ) {
        if (!in_array($frequency, [self::DAILY, self::WEEKLY], true)) {
            throw new \InvalidArgumentException('Unknown ranking frequency.');
        }
    }

    public static function default(): self
    {
        return new self(false, SerpDevice::Desktop, self::DAILY);
    }

    public function intervalDays(): int
    {
        return $this->frequency === self::WEEKLY ? 7 : 1;
    }
}

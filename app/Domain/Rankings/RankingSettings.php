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

    /**
     * @param SerpDevice $device the primary device: dashboards, competitors and alerts use it
     * @param bool $bothDevices also check the other device (1.6.0), for the mobile/desktop comparison
     */
    public function __construct(
        public bool $enabled,
        public SerpDevice $device,
        public string $frequency,
        public bool $bothDevices = false,
    ) {
        if (!in_array($frequency, [self::DAILY, self::WEEKLY], true)) {
            throw new \InvalidArgumentException('Unknown ranking frequency.');
        }
    }

    public static function default(): self
    {
        return new self(false, SerpDevice::Desktop, self::DAILY);
    }

    /** @return list<SerpDevice> the devices each check covers, the primary first */
    public function devices(): array
    {
        return $this->bothDevices ? [$this->device, $this->otherDevice()] : [$this->device];
    }

    public function otherDevice(): SerpDevice
    {
        return $this->device === SerpDevice::Desktop ? SerpDevice::Mobile : SerpDevice::Desktop;
    }

    public function intervalDays(): int
    {
        return $this->frequency === self::WEEKLY ? 7 : 1;
    }
}

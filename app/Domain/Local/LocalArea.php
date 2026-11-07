<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Local;

use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Where local searches are made: a point on the map and a zoom level
 * (Google Maps zoom; 13 ≈ a city, 15 ≈ a neighbourhood).
 */
final readonly class LocalArea
{
    public const array ZOOMS = [11 => 'Region', 12 => 'Large city', 13 => 'City', 14 => 'District', 15 => 'Neighbourhood', 16 => 'A few streets'];

    public function __construct(
        public float $latitude,
        public float $longitude,
        public int $zoom = 13,
    ) {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || !isset(self::ZOOMS[$zoom])) {
            throw new \InvalidArgumentException('Invalid local search area.');
        }
    }

    /**
     * Parses coordinates as Google Maps copies them ("43.8563, 18.4131").
     *
     * @throws ValidationException
     */
    public static function fromInput(mixed $coordinates, mixed $zoom): self
    {
        $errors = [];
        $text = is_string($coordinates) ? trim($coordinates) : '';
        $latitude = $longitude = 0.0;
        if (preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)$/', $text, $m) === 1) {
            $latitude = (float) $m[1];
            $longitude = (float) $m[2];
        }
        if (!isset($m[2]) || abs($latitude) > 90 || abs($longitude) > 180) {
            $errors['coordinates'] = 'Enter the coordinates as latitude, longitude, for example 43.8563, 18.4131.';
        }
        $level = is_string($zoom) && ctype_digit($zoom) ? (int) $zoom : (is_int($zoom) ? $zoom : 0);
        if (!isset(self::ZOOMS[$level])) {
            $errors['zoom'] = 'Choose the size of the area.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self(round($latitude, 6), round($longitude, 6), $level);
    }

    /**
     * "latitude,longitude,zoomz" as DataForSEO's location_coordinate expects it.
     */
    public function coordinate(): string
    {
        return sprintf('%s,%s,%dz', self::number($this->latitude), self::number($this->longitude), $this->zoom);
    }

    /**
     * "latitude,longitude,radius" (radius in metres, matching the zoom) as DataForSEO's Business Data API expects it.
     */
    public function radiusCoordinate(): string
    {
        $radius = match ($this->zoom) {
            11 => 40000,
            12 => 20000,
            13 => 10000,
            14 => 5000,
            15 => 2500,
            default => 1000,
        };

        return sprintf('%s,%s,%d', self::number($this->latitude), self::number($this->longitude), $radius);
    }

    public function label(): string
    {
        return sprintf('%s, %s', self::number($this->latitude), self::number($this->longitude));
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }
}

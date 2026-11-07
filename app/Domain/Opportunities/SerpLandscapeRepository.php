<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;

/**
 * The latest results page of each tracked keyword and device, taken from
 * the rank checks (no extra provider request). One row per keyword and
 * device; an older check never overwrites a newer one.
 */
interface SerpLandscapeRepository
{
    public const int TOP = 10;

    public function record(int $projectId, int $projectKeywordId, string $date, SerpSnapshot $serp): void;

    /** @return array<string, SerpLandscape> normalized keyword => latest results page */
    public function latest(int $projectId, SerpDevice $device): array;

    /**
     * Distinct result domains across the stored results pages, the most
     * frequent first (for the competitor-strength check).
     *
     * @return list<string>
     */
    public function domains(int $projectId, SerpDevice $device, int $limit): array;
}

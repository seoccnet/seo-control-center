<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

use SeoControlCenter\Domain\Rankings\AiOverview;
use SeoControlCenter\Domain\Rankings\SerpDevice;

/**
 * AI Overview history per keyword and the sources of the latest check,
 * always scoped by project.
 */
interface AiOverviewRepository
{
    /**
     * The project's primary domain (without "www."), or null when the project is gone.
     */
    public function projectDomain(int $projectId): ?string;

    /**
     * Stores one check: whether an AI Overview was shown, where the project
     * was cited, and (replacing the previous ones) its sources.
     */
    public function record(int $projectId, int $projectKeywordId, SerpDevice $device, string $date, ?AiOverview $overview, ?int $ownPosition, ?string $ownUrl): void;

    public function latestDate(int $projectId, SerpDevice $device): ?string;

    public function dateOnOrBefore(int $projectId, SerpDevice $device, string $date): ?string;

    /**
     * Keywords checked on $date, how many showed an AI Overview and in how many the project was cited.
     *
     * @return array{keywords: int, present: int, cited: int}
     */
    public function summary(int $projectId, SerpDevice $device, string $date): array;

    /**
     * Keywords checked on $date: AI Overviews first, cited ones first, then by keyword.
     *
     * @return list<AiOverviewKeyword>
     */
    public function keywords(int $projectId, SerpDevice $device, string $date, bool $onlyWithOverview, int $limit, int $offset): array;

    public function countKeywords(int $projectId, SerpDevice $device, string $date, bool $onlyWithOverview): int;

    /**
     * Domains cited most often in the latest AI Overviews of the project's keywords.
     *
     * @return list<array{domain: string, keywords: int}>
     */
    public function topDomains(int $projectId, SerpDevice $device, int $limit): array;
}

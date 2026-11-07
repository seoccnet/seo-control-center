<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Keywords;

/**
 * A keyword tracked by a project, with its latest metrics (null until fetched).
 */
final readonly class ProjectKeyword
{
    public function __construct(
        public string $publicId,
        public string $keyword,
        public KeywordLocale $locale,
        public string $createdAt,
        public ?KeywordMetrics $metrics,
        public ?string $metricsFetchedAt,
    ) {}
}

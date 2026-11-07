<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Projects\Project;

final readonly class BrandSettings
{
    public function __construct(public string $name, public KeywordLocale $locale, public bool $weekly, public int $userId) {}
    public function key(Project $project): string
    {
        return hash('sha256', json_encode([$this->name, $this->locale->key(), ResearchService::bareDomain($project->primaryDomain)], JSON_THROW_ON_ERROR));
    }
}

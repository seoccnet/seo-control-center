<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests\Setup;

use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Projects\Project;

/**
 * What the Foundations checks need to know about one user, workspace and
 * project, read once per request. Read-only; nothing here queues work.
 */
final readonly class FoundationsFacts
{
    public function __construct(
        public ?Project $project,
        public bool $admin,
        public bool $canCreate,
        public bool $canManageProvider,
        public bool $canIntegrate,
        public bool $canEdit,
        public bool $canSpend,
        public bool $searchIncluded,
        public bool $rankIncluded,
        public string $cron,
        public bool $connected,
        public bool $limited,
        public bool $sandbox,
        public bool $hasProfile,
        public ApiBudget $budget,
        public bool $budgetBlocked,
        public bool $noCredit,
        public bool $providerProblem,
        public bool $googleConfigured,
        public bool $linked,
        public bool $googleProblem,
        public bool $hasLinkableProperty,
        public int $keywordCount,
        public bool $trackingEnabled,
        public bool $currentImport,
        public bool $importFailed,
        public bool $searchReady,
        public bool $rankingReady,
        public bool $searchError,
        public bool $rankError,
    ) {}

    public function base(): ?string
    {
        return $this->project !== null ? '/projects/' . $this->project->publicId : null;
    }

    public function failed(): bool
    {
        return ($this->searchError && !$this->rankingReady) || ($this->rankError && !$this->searchReady);
    }
}

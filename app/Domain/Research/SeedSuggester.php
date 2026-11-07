<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\BusinessContext;

/**
 * Suggests seed keywords and modifiers for keyword research from a
 * business description (the workspace's AI assistant model). Runs in a
 * job, never in a browser request. Suggestions are words to look up, never
 * data: volumes and difficulty come from the keyword database.
 */
interface SeedSuggester
{
    public function available(int $workspaceId): bool;

    /** @throws IntegrationException */
    public function suggest(int $workspaceId, BusinessContext $context, string $domain, KeywordLocale $locale): SeedIdeas;
}

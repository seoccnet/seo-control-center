<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Research\SeedIdeas;
use SeoControlCenter\Domain\Research\SeedSuggester;

final class FakeSeedSuggester implements SeedSuggester
{
    public bool $available = true;

    /** @var array<mixed> raw model output */
    public array $output = ['seeds' => ['running shoes', 'trail shoes'], 'modifiers' => ['best', 'for beginners'], 'notes' => 'Product and comparison searches.'];

    public ?IntegrationException $fail = null;

    /** @var list<BusinessContext> */
    public array $calls = [];

    public function available(int $workspaceId): bool
    {
        return $this->available;
    }

    public function suggest(int $workspaceId, BusinessContext $context, string $domain, KeywordLocale $locale): SeedIdeas
    {
        $this->calls[] = $context;
        if ($this->fail !== null) {
            throw $this->fail;
        }

        return SeedIdeas::clean($this->output);
    }
}

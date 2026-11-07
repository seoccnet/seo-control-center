<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Backlinks\AnchorText;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\BacklinksProvider;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Scripted backlink data.
 */
final class FakeBacklinksProvider implements BacklinksProvider
{
    public bool $available = true;

    /** @var array<string, BacklinkProfile> by domain */
    public array $profiles = [];

    /** @var list<ReferringDomain> */
    public array $domains = [];

    /** @var list<ReferringDomain> */
    public array $new = [];

    /** @var list<ReferringDomain> */
    public array $lost = [];

    /** @var list<AnchorText> */
    public array $anchors = [];

    /** @var list<ReferringDomain> */
    public array $gap = [];

    /** @var list<\SeoControlCenter\Domain\Backlinks\PageBacklink> */
    public array $links = [];
    /** @var list<\SeoControlCenter\Domain\Backlinks\PageBacklink> */
    public array $lostLinks = [];

    public function pageLinks(int $workspaceId, ?int $projectId, string $domain, int $limit, ?string $since = null, bool $lost = false): array
    {
        $this->call('pageLinks', [$domain, $since, $lost]);
        return $lost ? $this->lostLinks : $this->links;
    }

    public ?IntegrationException $fail = null;

    /** @var list<array{call: string, args: list<mixed>}> */
    public array $calls = [];

    public function isAvailable(int $workspaceId): bool
    {
        return $this->available;
    }

    public function estimatedCost(int $requests, int $rows = 0): float
    {
        return 0.024 * $requests + 0.000036 * $rows;
    }

    public function profiles(int $workspaceId, ?int $projectId, array $domains): array
    {
        $this->call('profiles', [$domains]);

        return array_values(array_filter(array_map(fn(string $d): ?BacklinkProfile => $this->profiles[$d] ?? null, $domains)));
    }

    public function pageProfiles(int $workspaceId, ?int $projectId, array $urls): array
    {
        $this->call('pageProfiles', [$urls]);

        return array_values(array_filter(array_map(fn(string $u): ?BacklinkProfile => $this->profiles[$u] ?? null, $urls)));
    }

    public function referringDomains(int $workspaceId, ?int $projectId, string $domain, int $limit, ?string $since = null, bool $lost = false): array
    {
        $this->call('referringDomains', [$domain, $since, $lost]);

        return $since === null ? $this->domains : ($lost ? $this->lost : $this->new);
    }

    public function anchors(int $workspaceId, ?int $projectId, string $domain, int $limit): array
    {
        $this->call('anchors', [$domain]);

        return $this->anchors;
    }

    public function linkGap(int $workspaceId, ?int $projectId, string $domain, array $competitors, int $limit): array
    {
        $this->call('linkGap', [$domain, $competitors]);

        return $this->gap;
    }

    /**
     * @param list<mixed> $args
     */
    private function call(string $call, array $args): void
    {
        $this->calls[] = ['call' => $call, 'args' => $args];
        if ($this->fail !== null) {
            $error = $this->fail;
            $this->fail = null;

            throw $error;
        }
    }
}

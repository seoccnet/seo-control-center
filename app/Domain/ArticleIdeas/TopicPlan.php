<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

/**
 * A topic plan (ADR-041, G2): a pillar article idea with supporting
 * articles, searches to consider and your pages on the subject, an internal
 * link map and, when bought, the backlinks of the pillar's top competitor
 * pages.
 */
final readonly class TopicPlan
{
    public const string IDEA = 'idea';
    public const string SEARCH = 'search';
    public const string PAGE = 'page';
    public const string QUEUED = 'queued';
    public const string DONE = 'done';
    public const string FAILED = 'failed';
    /** A paid backlink check is repeated without a second confirmation only after this many days. */
    public const int BACKLINKS_REPEAT_DAYS = 30;

    /**
     * @param list<array{type: string, key: string, label: string, volume: int, kept: bool, url: ?string}> $members
     *        type idea (key = idea key), search (key = the search) or page (key = your URL)
     * @param array{internal: list<array{from: string, to: string, anchor: string}>, pages: list<array{source_url: string, to: string, anchor: string, paragraph: string}>, audit: ?string}|null $links
     *        from/to: member keys ("pillar" for the pillar); pages: links from your crawled pages
     * @param list<array{url: string, referring_domains: ?int, backlinks: ?int, rank: ?int}>|null $backlinks
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public string $pillarKey,
        public string $pillarKeyword,
        public array $members,
        public ?array $links,
        public ?string $linksStatus,
        public ?string $linksBuiltAt,
        public ?array $backlinks,
        public ?string $backlinksStatus,
        public ?string $backlinksError,
        public ?string $backlinksAt,
        public float $backlinksCost,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @return list<array{type: string, key: string, label: string, volume: int, kept: bool, url: ?string}> */
    public function kept(string $type): array
    {
        return array_values(array_filter($this->members, static fn(array $m): bool => $m['type'] === $type && $m['kept']));
    }

    /** The label of a member key, "pillar" included. */
    public function label(string $key): string
    {
        if ($key === 'pillar') {
            return $this->pillarKeyword;
        }
        foreach ($this->members as $member) {
            if ($member['key'] === $key) {
                return $member['label'];
            }
        }

        return $key;
    }

    /** Referring domains that would put a new pillar article level with the strongest competitor page read. */
    public function referringDomainsToMatch(): ?int
    {
        $values = array_filter(array_column($this->backlinks ?? [], 'referring_domains'), is_int(...));

        return $values === [] ? null : max($values);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return [
            'plan_id' => $this->publicId,
            'pillar' => ['idea_key' => $this->pillarKey, 'main_keyword' => $this->pillarKeyword],
            'members' => $this->members,
            'links' => $this->links === null ? null : [...$this->links, 'status' => $this->linksStatus, 'built_at' => $this->linksBuiltAt],
            'backlinks' => $this->backlinksStatus === null ? null : [
                'status' => $this->backlinksStatus,
                'checked_at' => $this->backlinksAt,
                'error' => $this->backlinksError,
                'pages' => $this->backlinks ?? [],
                'referring_domains_to_match' => $this->referringDomainsToMatch(),
                'cost' => $this->backlinksCost,
            ],
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}

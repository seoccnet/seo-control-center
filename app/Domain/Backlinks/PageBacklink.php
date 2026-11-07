<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Backlinks;

final readonly class PageBacklink
{
    public function __construct(public string $sourceUrl, public string $targetUrl, public string $anchor, public ?int $domainRank, public bool $dofollow, public ?string $firstSeen, public ?string $lostOn) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['source_url' => $this->sourceUrl, 'target_url' => $this->targetUrl, 'anchor' => $this->anchor, 'domain_rank' => $this->domainRank,
            'dofollow' => $this->dofollow, 'first_seen' => $this->firstSeen, 'lost_on' => $this->lostOn];
    }

    /** @param array<mixed> $row */
    public static function fromArray(array $row): ?self
    {
        if (!is_string($row['source_url'] ?? null) || !is_string($row['target_url'] ?? null)) {
            return null;
        }
        foreach ([$row['source_url'], $row['target_url']] as $url) {
            if (mb_strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)) {
                return null;
            }
        }
        return new self(
            $row['source_url'],
            $row['target_url'],
            is_string($row['anchor'] ?? null) ? mb_substr($row['anchor'], 0, 1000) : '',
            is_int($row['domain_rank'] ?? null) ? max(0, min(100, $row['domain_rank'])) : null,
            ($row['dofollow'] ?? false) === true,
            is_string($row['first_seen'] ?? null) ? $row['first_seen'] : null,
            is_string($row['lost_on'] ?? null) ? $row['lost_on'] : null
        );
    }
}

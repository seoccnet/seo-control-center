<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Backlinks;

/**
 * A web page that names the brand (1.6.0), from a content index such as
 * DataForSEO Content Analysis. Whether the page also links to the site is
 * not part of the index; it is read from the stored referring domains.
 */
final readonly class WebMention
{
    public function __construct(
        public string $url,
        public string $domain,
        public ?string $title,
        public ?string $snippet,
        public ?int $domainRank,
        public ?string $published,
        public ?string $pageType,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['url' => $this->url, 'domain' => $this->domain, 'title' => $this->title, 'snippet' => $this->snippet, 'domain_rank' => $this->domainRank, 'published' => $this->published, 'page_type' => $this->pageType];
    }

    /** @param array<mixed> $row */
    public static function fromArray(array $row): ?self
    {
        $url = $row['url'] ?? null;
        $domain = $row['domain'] ?? null;
        // Pages in other languages often have unencoded letters in their address; validate an encoded copy.
        $encoded = is_string($url) ? (string) preg_replace_callback('/[^\x21-\x7e]/u', static fn(array $m): string => rawurlencode($m[0]), $url) : '';
        if (!is_string($url) || !is_string($domain) || mb_strlen($url) > 2048 || filter_var($encoded, FILTER_VALIDATE_URL) === false
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || preg_match('/^[a-z0-9.-]{1,253}$/', $domain) !== 1) {
            return null;
        }

        return new self(
            $url,
            $domain,
            is_string($row['title'] ?? null) ? mb_substr($row['title'], 0, 300) : null,
            is_string($row['snippet'] ?? null) ? mb_substr($row['snippet'], 0, 600) : null,
            is_int($row['domain_rank'] ?? null) ? max(0, min(1000, $row['domain_rank'])) : null,
            is_string($row['published'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['published']) === 1 ? $row['published'] : null,
            is_string($row['page_type'] ?? null) ? mb_substr($row['page_type'], 0, 40) : null,
        );
    }

    /**
     * Whether one of the domains linking to the site is this page's site.
     *
     * @param list<string> $referringDomains
     */
    public function linksFrom(array $referringDomains): bool
    {
        foreach ($referringDomains as $domain) {
            if ($this->sameSite($domain)) {
                return true;
            }
        }

        return false;
    }

    /** The same domain, or one is a subdomain of the other. */
    public function sameSite(string $domain): bool
    {
        $mine = self::bare($this->domain);
        $theirs = self::bare($domain);

        return $theirs !== '' && ($mine === $theirs || str_ends_with($mine, '.' . $theirs) || str_ends_with($theirs, '.' . $mine));
    }

    private static function bare(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }
}

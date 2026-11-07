<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

/**
 * A URL found during an audit and, once fetched, what the crawler saw.
 * State: pending (not fetched yet), done (fetched or failed), blocked
 * (robots.txt) or skipped (not fetchable, for example http://).
 */
final readonly class AuditPage
{
    /** Depth of a page the home page's links do not reach (found in the sitemap, or only linked from such pages). */
    public const int UNLINKED = 65535;

    /**
     * @param list<string>|null $schemaTypes
     */
    public function __construct(
        public int $id,
        public string $url,
        public int $depth,
        public string $state,
        public ?int $statusCode = null,
        public ?string $contentType = null,
        public ?string $redirectTo = null,
        public ?int $durationMs = null,
        public ?int $bytes = null,
        public bool $truncated = false,
        public ?string $title = null,
        public ?string $description = null,
        public ?int $h1Count = null,
        public ?string $h1 = null,
        public ?string $canonical = null,
        public bool $noindex = false,
        public ?int $wordCount = null,
        public int $internalLinks = 0,
        public int $externalLinks = 0,
        public ?string $error = null,
        /** Structured data types; null when the page was crawled before they were recorded. */
        public ?array $schemaTypes = null,
        public ?string $ogType = null,
        /** Whether the page names an author; null when unknown. */
        public ?bool $hasAuthor = null,
        /** Other sites linked from the text (0.42.0); null when the page was crawled before this was recorded. */
        public ?int $sourceLinks = null,
        /**
         * What the page offers AI answers to quote (0.43.0); null when the page was crawled before this was recorded.
         *
         * @var array{questions: int, tables: int, lists: int, figures: int, lead_words: ?int, updated: bool}|null
         */
        public ?array $citation = null,
        /**
         * The business details the page states (0.46.0); null when the page was crawled before this was recorded.
         *
         * @var array{types: list<string>, name: ?string, phones: list<string>, address: ?string}|null
         */
        public ?array $business = null,
    ) {}

    public function isHtml(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 200 && $this->statusCode < 300
            && $this->contentType !== null && str_contains(strtolower($this->contentType), 'html');
    }

    public function isLinked(): bool
    {
        return $this->depth !== self::UNLINKED;
    }

    public function isRedirect(): bool
    {
        return $this->statusCode !== null && $this->statusCode >= 300 && $this->statusCode < 400;
    }

    /**
     * The result of fetching this page.
     */
    public function withResult(PageResult $result): self
    {
        return new self(
            $this->id,
            $this->url,
            $this->depth,
            $result->state,
            $result->statusCode,
            $result->contentType,
            $result->redirectTo,
            $result->durationMs,
            $result->bytes,
            $result->truncated,
            $result->page?->title,
            $result->page?->description,
            $result->page?->h1Count,
            $result->page?->h1,
            $result->page?->canonical,
            $result->noindex,
            $result->page?->wordCount,
            $result->internalLinks,
            $result->externalLinks,
            $result->error,
            $result->page?->schemaTypes,
            $result->page?->ogType,
            $result->page?->hasAuthor,
            $result->page?->sourceLinks,
            $result->page !== null && $result->page->citation !== [] ? $result->page->citation : null,
            $result->page !== null && $result->page->business !== [] ? $result->page->business : null,
        );
    }

    public const array ARTICLE_TYPES = ['Article', 'BlogPosting', 'NewsArticle', 'TechArticle', 'Report'];
    public const array PRODUCT_TYPES = ['Product', 'ProductGroup', 'IndividualProduct', 'ProductModel'];
    public const array CATEGORY_TYPES = ['CollectionPage', 'ItemList', 'OfferCatalog'];
    /** Markup that answers questions on the page itself. */
    public const array QUESTION_TYPES = ['FAQPage', 'QAPage'];

    /** Pages whose structured data or Open Graph type marks them as articles. */
    public function isArticle(): bool
    {
        return $this->ogType === 'article' || array_intersect($this->schemaTypes ?? [], self::ARTICLE_TYPES) !== [];
    }

    /** Pages whose Open Graph type or structured data marks them as a product (0.44.0). */
    public function isProduct(): bool
    {
        return in_array($this->ogType, ['product', 'og:product', 'product.item', 'product.group'], true) || array_intersect($this->schemaTypes ?? [], self::PRODUCT_TYPES) !== [];
    }

    /**
     * Listing pages of products or posts, marked as a collection or item list in
     * their structured data (0.44.0). Products and the home page are not
     * categories, even when they carry a related-items list.
     */
    public function isCategory(): bool
    {
        return !$this->isProduct() && !$this->isArticle() && (string) parse_url($this->url, PHP_URL_PATH) !== '/' && (string) parse_url($this->url, PHP_URL_PATH) !== ''
            && array_intersect($this->schemaTypes ?? [], self::CATEGORY_TYPES) !== [];
    }
}

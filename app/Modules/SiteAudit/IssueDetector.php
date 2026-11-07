<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Modules\SiteAudit\Crawler\Pagination;

/**
 * Turns the crawled pages of an audit into issues and a health score.
 * Content checks (title, description, headings, text) apply to indexable
 * HTML pages only: pages with noindex or a canonical elsewhere are not
 * meant to rank.
 */
final class IssueDetector
{
    public const int SLOW_MS = 2000;
    public const int TITLE_MAX = 60;
    public const int TITLE_MIN = 10;
    public const int DESCRIPTION_MAX = 160;
    public const int THIN_WORDS = 200;
    /** Articles shorter than this rarely state facts that need a source (0.42.0). */
    public const int SOURCE_WORDS = 600;
    /** Pages more than this many clicks from the start page are reported as deep. */
    public const int MAX_DEPTH = 3;
    /** Paths of pages that state who runs the site (0.50.0). */
    public const string CONTACT_PATH = '#(^|/)(contact|contact-us|kontakt|contacto|contatti|impressum|imprint|about|about-us|o-nama|uber-uns|ueber-uns)(/|\.html?$|$)#i';
    /** Organization types that describe who runs a site (LocalBusiness types count too). */
    public const array ORGANIZATION_TYPES = ['Organization', 'Corporation', 'OnlineStore', 'OnlineBusiness', 'NGO', 'NewsMediaOrganization', 'EducationalOrganization'];

    /**
     * @param list<AuditPage> $pages
     * @param array<int, int> $inbound page id => number of pages linking to it
     * @param bool $complete the crawl found every page it could (no page limit hit); only then is an unlinked page certainly not linked
     * @param list<array{pageId: int, type: IssueType, detail: ?string}> $resourceIssues
     * @return array{issues: list<array{pageId: int, type: IssueType, detail: ?string}>, score: ?int, counts: array{error: int, warning: int, notice: int}}
     */
    public static function detect(array $pages, array $inbound, bool $complete = true, array $resourceIssues = []): array
    {
        $byUrl = [];
        foreach ($pages as $page) {
            $byUrl[$page->url] = $page;
        }
        $issues = $resourceIssues;
        $add = static function (AuditPage $page, IssueType $type, ?string $detail = null) use (&$issues): void {
            $issues[] = ['pageId' => $page->id, 'type' => $type, 'detail' => $detail !== null ? mb_substr($detail, 0, 500) : null];
        };
        $linkedFrom = static function (AuditPage $page) use ($inbound): string {
            $count = $inbound[$page->id] ?? 0;

            return $count > 0 ? sprintf(' · linked from %d page%s', $count, $count === 1 ? '' : 's') : '';
        };

        $titles = [];
        $descriptions = [];
        foreach ($pages as $page) {
            if ($page->state === 'blocked') {
                $add($page, IssueType::BlockedByRobots, ltrim($linkedFrom($page), ' ·'));
                continue;
            }
            if ($page->state === 'skipped') {
                if (str_starts_with($page->url, 'http://')) {
                    $add($page, IssueType::HttpUrl, ltrim($linkedFrom($page), ' ·'));
                }
                continue;
            }
            if ($page->state !== 'done') {
                continue;
            }
            if ($complete && !$page->isLinked() && ($inbound[$page->id] ?? 0) === 0) {
                $add($page, IssueType::NotLinked);
            }
            if ($page->statusCode === null || $page->statusCode === 0) {
                $add($page, IssueType::Unreachable, ($page->error ?? 'No answer.') . $linkedFrom($page));
                continue;
            }
            if ($page->statusCode >= 500) {
                $add($page, IssueType::ServerError, 'HTTP ' . $page->statusCode . $linkedFrom($page));
                continue;
            }
            if ($page->statusCode >= 400) {
                $add($page, in_array($page->statusCode, [401, 403, 429], true) ? IssueType::FetchRefused : IssueType::BrokenPage, 'HTTP ' . $page->statusCode . $linkedFrom($page));
                continue;
            }
            if ($page->isRedirect()) {
                $target = $page->redirectTo !== null ? ($byUrl[$page->redirectTo] ?? null) : null;
                if ($target !== null && $target->isRedirect()) {
                    $add($page, IssueType::RedirectChain, sprintf('%s → %s → %s', $page->statusCode, self::relative($target->url, $page->url), $target->redirectTo !== null ? self::relative($target->redirectTo, $page->url) : '?'));
                } else {
                    $add($page, IssueType::Redirect, sprintf('%d → %s', $page->statusCode, $page->redirectTo !== null ? self::relative($page->redirectTo, $page->url) : 'no Location header'));
                }
                continue;
            }
            if ($page->durationMs !== null && $page->durationMs > self::SLOW_MS) {
                $add($page, IssueType::SlowPage, sprintf('%.1f s', $page->durationMs / 1000));
            }
            if ($page->truncated) {
                $add($page, IssueType::LargePage);
            }
            if (!$page->isHtml()) {
                continue;
            }
            if ($page->noindex) {
                $add($page, IssueType::Noindex);
                continue;
            }
            if ($page->canonical !== null && $page->canonical !== $page->url) {
                $add($page, IssueType::CanonicalElsewhere, $page->canonical);
                continue;
            }
            if ($page->title === null) {
                $add($page, IssueType::MissingTitle);
            } else {
                $length = mb_strlen($page->title);
                if ($length > self::TITLE_MAX) {
                    $add($page, IssueType::TitleTooLong, sprintf('%d characters', $length));
                } elseif ($length < self::TITLE_MIN) {
                    $add($page, IssueType::TitleTooShort, sprintf('%d characters', $length));
                }
                $titles[mb_strtolower($page->title)][Pagination::series($page->url)][] = $page;
            }
            if ($page->description === null) {
                $add($page, IssueType::MissingDescription);
            } else {
                $length = mb_strlen($page->description);
                if ($length > self::DESCRIPTION_MAX) {
                    $add($page, IssueType::DescriptionTooLong, sprintf('%d characters', $length));
                }
                $descriptions[mb_strtolower($page->description)][Pagination::series($page->url)][] = $page;
            }
            if ((int) $page->h1Count === 0) {
                $add($page, IssueType::MissingH1);
            } elseif ($page->h1Count > 1) {
                $add($page, IssueType::MultipleH1, sprintf('%d H1 headings', $page->h1Count));
            }
            if ($page->wordCount !== null && $page->wordCount < self::THIN_WORDS) {
                $add($page, IssueType::ThinContent, sprintf('%d words', $page->wordCount));
            }
            // Later pages of a paginated listing are deep and carry no page-level markup by design.
            $laterPage = (Pagination::pageNumber($page->url) ?? 1) > 1;
            if (!$laterPage && $page->depth > self::MAX_DEPTH && $page->depth !== AuditPage::UNLINKED) {
                $add($page, IssueType::DeepPage, sprintf('%d clicks from the start page', $page->depth));
            }
            // Null signals mean the page was crawled before they were recorded: unknown, not missing.
            if (!$laterPage && $page->schemaTypes === []) {
                $add($page, IssueType::MissingStructuredData, match ($page->ogType) {
                    'product', 'og:product' => 'Marked as a product (og:type); add Product markup with price and availability',
                    'article' => 'Marked as an article (og:type); add Article markup with author and dates',
                    default => null,
                });
            }
            if ($page->hasAuthor === false && $page->isArticle()) {
                $add($page, IssueType::MissingAuthor);
            }
            // Null means crawled before source links were counted: unknown, not missing.
            if (!$laterPage && $page->sourceLinks === 0 && $page->isArticle() && ($page->wordCount ?? 0) >= self::SOURCE_WORDS) {
                $add($page, IssueType::MissingSources, sprintf('%d words, no link to another site in the text', $page->wordCount));
            }
            // Structured data that describes the site but not the page's own content (0.44.0).
            $missingType = $laterPage || $page->schemaTypes === null || $page->schemaTypes === [] ? null : self::missingContentType($page);
            if ($missingType !== null) {
                $add($page, IssueType::MissingContentMarkup, $missingType);
            }
            // Null citation signals mean the page was crawled before 0.43.0: unknown, not missing.
            if (!$laterPage && $page->citation !== null && ($page->isProduct() || $page->isCategory())) {
                $kind = $page->isProduct() ? 'Product page' : 'Category page';
                if ($page->citation['questions'] === 0 && array_intersect($page->schemaTypes ?? [], AuditPage::QUESTION_TYPES) === []) {
                    $add($page, IssueType::MissingQuestions, $kind . ': no heading phrased as a question and no FAQ markup');
                }
                if ($page->isProduct() && $page->citation['tables'] === 0) {
                    $add($page, IssueType::MissingSpecTable, 'No table or list of terms (such as size, material or flowering time)');
                }
            }
        }
        $business = self::missingBusinessDetails($pages);
        if ($business !== null) {
            $add($business[0], IssueType::MissingBusinessDetails, $business[1]);
        }
        foreach ([[$titles, IssueType::DuplicateTitle], [$descriptions, IssueType::DuplicateDescription]] as [$groups, $type]) {
            // The pages of one paginated series count as one page, reported on its first page.
            foreach ($groups as $series) {
                if (count($series) < 2) {
                    continue;
                }
                $first = [];
                foreach ($series as $key => $members) {
                    usort($members, static fn(AuditPage $a, AuditPage $b): int => (Pagination::pageNumber($a->url) ?? 0) <=> (Pagination::pageNumber($b->url) ?? 0));
                    $first[$key] = $members[0];
                }
                foreach ($first as $key => $page) {
                    $others = array_values(array_map(static fn(AuditPage $p): string => self::relative($p->url, $page->url), array_diff_key($first, [$key => true])));
                    sort($others);
                    $more = count($others) - 2;
                    $add($page, $type, sprintf(
                        'Same %s as %s%s',
                        $type === IssueType::DuplicateTitle ? 'title' : 'description',
                        implode(', ', array_slice($others, 0, 2)),
                        $more > 0 ? sprintf(' and %d more', $more) : '',
                    ));
                }
            }
        }

        return ['issues' => $issues, ...self::score($pages, $issues)];
    }

    /**
     * What the site does not state about who runs it (0.50.0), checked once
     * on the start page and the contact pages: Organization or
     * LocalBusiness markup, a phone number (tel: link or markup) and an
     * address. Reported on the start page; null when nothing is missing
     * or the start page was crawled before business details were read.
     *
     * @param list<AuditPage> $pages
     * @return array{AuditPage, string}|null
     */
    private static function missingBusinessDetails(array $pages): ?array
    {
        $home = null;
        $read = [];
        foreach ($pages as $page) {
            if ($page->state !== 'done' || !$page->isHtml() || $page->business === null) {
                continue;
            }
            if ($page->depth === 0) {
                $home = $page;
                $read[] = $page;
            } elseif (preg_match(self::CONTACT_PATH, (string) parse_url($page->url, PHP_URL_PATH)) === 1) {
                $read[] = $page;
            }
        }
        if ($home === null) {
            return null;
        }
        $types = array_merge(...array_map(static fn(AuditPage $p): array => [...($p->schemaTypes ?? []), ...($p->business['types'] ?? [])], $read));
        $missing = [];
        if (array_intersect($types, [...self::ORGANIZATION_TYPES, ...\SeoControlCenter\Domain\Local\NapCheck::LOCAL_TYPES]) === []) {
            $missing[] = 'Organization or LocalBusiness markup';
        }
        if (array_filter($read, static fn(AuditPage $p): bool => ($p->business['phones'] ?? []) !== []) === []) {
            $missing[] = 'a phone number (tel: link)';
        }
        if (array_filter($read, static fn(AuditPage $p): bool => ($p->business['address'] ?? null) !== null) === []) {
            $missing[] = 'an address in structured data';
        }
        if ($missing === []) {
            return null;
        }
        $checked = count($read) === 1 ? 'the home page (no contact page found)' : sprintf('the home page and %d contact page%s', count($read) - 1, count($read) === 2 ? '' : 's');

        return [$home, 'Not found on ' . $checked . ': ' . implode(', ', $missing)];
    }

    /**
     * What a page with structured data lacks for its own content (0.44.0): a
     * product (by its Open Graph type) without Product markup, or an article
     * (by its Open Graph type or address) without Article markup. Null when
     * nothing is missing or the kind of page is unknown.
     */
    private static function missingContentType(AuditPage $page): ?string
    {
        $types = $page->schemaTypes ?? [];
        $has = implode(', ', array_slice($types, 0, 3)) . (count($types) > 3 ? ' …' : '');
        if (in_array($page->ogType, ['product', 'og:product', 'product.item', 'product.group'], true) && array_intersect($types, AuditPage::PRODUCT_TYPES) === []) {
            return sprintf('Marked as a product (og:type) but its markup is only %s; add Product markup with price and availability', $has);
        }
        $article = $page->ogType === 'article' || \SeoControlCenter\Domain\Opportunities\Rules\ContentRefreshRule::article($page->url);
        if ($article && array_intersect($types, AuditPage::ARTICLE_TYPES) === [] && array_intersect($types, [...AuditPage::PRODUCT_TYPES, ...AuditPage::CATEGORY_TYPES]) === []) {
            return sprintf('An article (%s) whose markup is only %s; add Article or BlogPosting markup with author and dates', $page->ogType === 'article' ? 'og:type article' : 'by its address', $has);
        }

        return null;
    }

    /**
     * $url as a path when it is on the same scheme and host as $from.
     */
    private static function relative(string $url, string $from): string
    {
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $from);

        return str_starts_with($url, $origin . '/') ? substr($url, strlen($origin)) : $url;
    }

    /**
     * 100 minus the share of checked addresses with an error (full weight)
     * or with warnings only (half weight); null when nothing could be fetched.
     *
     * @param list<AuditPage> $pages
     * @param list<array{pageId: int, type: IssueType, detail: ?string}> $issues
     * @return array{score: ?int, counts: array{error: int, warning: int, notice: int}}
     */
    private static function score(array $pages, array $issues): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'notice' => 0];
        $worst = [];
        foreach ($issues as $issue) {
            $rank = $issue['type']->severityRank();
            match ($rank) {
                0 => $counts['error']++,
                1 => $counts['warning']++,
                default => $counts['notice']++,
            };
            $worst[$issue['pageId']] = min($worst[$issue['pageId']] ?? 2, $rank);
        }
        if (array_filter($pages, static fn(AuditPage $p): bool => $p->state === 'done') === []) {
            return ['score' => null, 'counts' => $counts];
        }
        $penalty = 0.0;
        foreach ($worst as $rank) {
            $penalty += match ($rank) {
                0 => 1.0,
                1 => 0.5,
                default => 0.0,
            };
        }

        $checked = count(array_filter($pages, static fn(AuditPage $p): bool => $p->state !== 'pending'));

        return ['score' => max(0, min(100, (int) round(100 * (1 - $penalty / max(1, $checked))))), 'counts' => $counts];
    }
}

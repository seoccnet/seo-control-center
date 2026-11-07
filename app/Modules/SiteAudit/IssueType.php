<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

/**
 * The problems a site audit reports, each with a fixed severity.
 */
enum IssueType: string
{
    case BrokenPage = 'broken_page';
    case FetchRefused = 'fetch_refused';
    case ServerError = 'server_error';
    case Unreachable = 'unreachable';
    case MissingTitle = 'missing_title';
    case RedirectChain = 'redirect_chain';
    case HttpUrl = 'http_url';
    case DuplicateTitle = 'duplicate_title';
    case MissingDescription = 'missing_description';
    case DuplicateDescription = 'duplicate_description';
    case MissingH1 = 'missing_h1';
    case SlowPage = 'slow_page';
    case LargePage = 'large_page';
    case Redirect = 'redirect';
    case TitleTooLong = 'title_too_long';
    case TitleTooShort = 'title_too_short';
    case DescriptionTooLong = 'description_too_long';
    case MultipleH1 = 'multiple_h1';
    case Noindex = 'noindex';
    case CanonicalElsewhere = 'canonical_elsewhere';
    case ThinContent = 'thin_content';
    case BlockedByRobots = 'blocked_by_robots';
    case NotLinked = 'not_linked';
    case BrokenAsset = 'broken_asset';
    case BrokenExternalLink = 'broken_external_link';
    case MissingImageAlt = 'missing_image_alt';
    case MixedContent = 'mixed_content';
    case ResourceUnavailable = 'resource_unavailable';
    case DeepPage = 'deep_page';
    case MissingStructuredData = 'missing_structured_data';
    case MissingAuthor = 'missing_author';
    case MissingSources = 'missing_sources';
    case MissingContentMarkup = 'missing_content_markup';
    case MissingQuestions = 'missing_questions';
    case MissingSpecTable = 'missing_spec_table';
    case MissingBusinessDetails = 'missing_business_details';

    public function severity(): string
    {
        return match ($this) {
            self::BrokenPage, self::ServerError, self::Unreachable, self::MissingTitle => 'error',
            self::RedirectChain, self::HttpUrl, self::DuplicateTitle, self::MissingDescription,
            self::DuplicateDescription, self::MissingH1, self::SlowPage, self::LargePage,
            self::BrokenAsset, self::BrokenExternalLink, self::MixedContent, self::FetchRefused => 'warning',
            default => 'notice',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::BrokenAsset => 'Broken image, script or stylesheet',
            self::BrokenExternalLink => 'Broken external link',
            self::MissingImageAlt => 'Image missing alt attribute',
            self::MixedContent => 'Asset uses insecure HTTP',
            self::ResourceUnavailable => 'Resource could not be verified',
            self::DeepPage => 'Deep in the site structure',
            self::MissingStructuredData => 'No structured data',
            self::MissingAuthor => 'Article without an author',
            self::MissingSources => 'Article without source links',
            self::MissingContentMarkup => 'Structured data without the page’s own type',
            self::MissingQuestions => 'Product or category page without answered questions',
            self::MissingSpecTable => 'Product page without a specification table',
            self::MissingBusinessDetails => 'Business details missing',
            self::BrokenPage => 'Broken page (4xx)',
            self::FetchRefused => 'Access denied or rate limited',
            self::ServerError => 'Server error (5xx)',
            self::Unreachable => 'Not reachable',
            self::MissingTitle => 'Missing title',
            self::RedirectChain => 'Redirect chain',
            self::HttpUrl => 'Linked over http://',
            self::DuplicateTitle => 'Duplicate title',
            self::MissingDescription => 'Missing meta description',
            self::DuplicateDescription => 'Duplicate meta description',
            self::MissingH1 => 'Missing H1',
            self::SlowPage => 'Slow response',
            self::LargePage => 'Very large page',
            self::Redirect => 'Redirect',
            self::TitleTooLong => 'Title too long',
            self::TitleTooShort => 'Title too short',
            self::DescriptionTooLong => 'Meta description too long',
            self::MultipleH1 => 'Several H1 headings',
            self::Noindex => 'Excluded by noindex',
            self::CanonicalElsewhere => 'Canonical points elsewhere',
            self::ThinContent => 'Little text',
            self::BlockedByRobots => 'Blocked by robots.txt',
            self::NotLinked => 'Not linked internally',
        };
    }

    public function advice(): string
    {
        return match ($this) {
            self::BrokenAsset => 'A sampled image, script or stylesheet returned HTTP 404 or 410. Repair its URL or restore the file. JavaScript-rendered and CSS-discovered assets are not inspected.',
            self::BrokenExternalLink => 'A sampled external link returned HTTP 404 or 410. Replace or remove it after checking manually. Redirects are not followed; access-denied responses are not marked broken.',
            self::MissingImageAlt => 'An image has no alt attribute. Describe meaningful images; use alt="" for decorative images. An empty attribute is valid and is not flagged.',
            self::MixedContent => 'The HTTPS page references an asset over HTTP. Switch it to HTTPS to prevent browser blocking. The insecure URL was not fetched.',
            self::ResourceUnavailable => 'A status probe timed out, failed or returned a server error. This is not proof of a broken link. Verify manually; robots-blocked and access-denied targets are excluded.',
            self::DeepPage => 'The page is more than three clicks from the start page. Deep pages are crawled less often and get less internal link weight. Link to it from its category, a hub page or related content.',
            self::MissingStructuredData => 'The page has no structured data (JSON-LD or microdata). Markup such as Product (price, availability, reviews), Article (author, dates), BreadcrumbList or LocalBusiness helps search engines and AI answers understand it and can earn rich results.',
            self::MissingAuthor => 'The article names no author: no author markup, byline or author meta. A visible author with a bio and credentials helps readers, search engines and AI answers trust the content.',
            self::MissingSources => 'The article has no link from its text to another site. Citing the sources of facts and figures inline, with a link to each, helps readers and AI answers trust and quote the page. Menu, card and footer links do not count.',
            self::MissingContentMarkup => 'The page has structured data, but only for the site (breadcrumbs, organization, website), not for what the page is. Add Product markup to product pages and Article or BlogPosting markup to articles, so search engines and AI answers can read the price, availability, author and dates.',
            self::MissingQuestions => 'The product or category page answers no buyer questions on the page: no heading phrased as a question and no FAQ markup. Add a short section with the questions buyers ask before choosing, each with a direct answer. Google no longer shows FAQ rich results, so the value is in the answers themselves.',
            self::MissingBusinessDetails => 'Neither the home page nor a contact page states some of the details that tell search engines and shoppers who runs the site: Organization (or LocalBusiness) structured data, a phone number as a tel: link, or an address. Add them to the home or contact page, the same as everywhere else the business is listed.',
            self::MissingSpecTable => 'The product page has no table or list of terms with its key facts. Put specifications (size, material, compatibility, flowering time …) in a table or a list of terms, so shoppers, search engines and AI answers can compare them at a glance.',
            self::BrokenPage => 'Links on your site lead to a page that does not exist. Fix or remove the links, or redirect the address to the right page.',
            self::FetchRefused => 'The server refused the crawler with HTTP 401, 403 or 429. Check access rules or rate limits; this is not proof that the page is missing.',
            self::ServerError => 'The server failed to deliver the page. Search engines drop pages that keep failing; check the server or application logs.',
            self::Unreachable => 'The page did not answer (timeout, connection or certificate error).',
            self::MissingTitle => 'The title is the headline in search results. Give every page a unique, descriptive title.',
            self::RedirectChain => 'The redirect leads to another redirect. Point links and the first redirect straight at the final address.',
            self::HttpUrl => 'Internal links use http:// instead of https://. Change them to https:// so visitors and crawlers skip the redirect.',
            self::DuplicateTitle => 'Several pages share the same title, so search engines cannot tell them apart. Make each title unique.',
            self::MissingDescription => 'Without a meta description, search engines pick a text snippet themselves. Write a short summary of the page.',
            self::DuplicateDescription => 'Several pages share the same meta description. Describe each page individually.',
            self::MissingH1 => 'The page has no main heading (H1). A clear H1 tells readers and search engines what the page is about.',
            self::SlowPage => 'The server took more than 2 seconds to deliver the HTML. Slow pages rank and convert worse; check caching and hosting.',
            self::LargePage => 'The HTML is larger than 2 MB and was only partly read. Large pages load slowly; move inline data and images out of the HTML.',
            self::Redirect => 'Internal links point at an address that redirects. Link to the final address instead.',
            self::TitleTooLong => 'Titles longer than about 60 characters are cut off in search results.',
            self::TitleTooShort => 'Titles shorter than 10 characters say little about the page.',
            self::DescriptionTooLong => 'Meta descriptions longer than about 160 characters are cut off in search results.',
            self::MultipleH1 => 'The page has several H1 headings. One main heading per page is clearer.',
            self::Noindex => 'The page asks search engines not to index it (robots meta tag or X-Robots-Tag). Check that this is intended.',
            self::CanonicalElsewhere => 'The canonical tag names a different address, so search engines index that one instead. Check that this is intended.',
            self::ThinContent => 'The page has fewer than 200 words of text. Pages with little content rarely rank, unless that is their purpose (contact, login).',
            self::NotLinked => 'The page is in your sitemap, but no checked page links to it. Visitors cannot reach it by clicking, and search engines rate such orphan pages lower. Link to it from a related page or the navigation.',
            self::BlockedByRobots => 'robots.txt disallows the page, so the audit did not fetch it and search engines will not crawl it either.',
        };
    }

    public function severityRank(): int
    {
        return match ($this->severity()) {
            'error' => 0,
            'warning' => 1,
            default => 2,
        };
    }
}

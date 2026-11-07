<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/**
 * The kind of page a search result is, from its URL, domain and title:
 * product, category, review/comparison, article, tool, forum, video,
 * reference, local directory, service page or home page. Explainable
 * patterns; a result that matches none is "other" and never counts towards
 * the dominant format.
 */
final class SerpFormat
{
    public const string PRODUCT = 'product';
    public const string CATEGORY = 'category';
    public const string REVIEW = 'review';
    public const string ARTICLE = 'article';
    public const string TOOL = 'tool';
    public const string FORUM = 'forum';
    public const string VIDEO = 'video';
    public const string REFERENCE = 'reference';
    public const string LOCAL = 'local';
    public const string SERVICE = 'service';
    public const string HOME = 'homepage';
    public const string OTHER = 'other';

    /** A format holds the results page when at least this share of the classified top results has it… */
    public const float DOMINANT_SHARE = 0.5;
    /** …and at least this many results. */
    public const int DOMINANT_MIN = 3;

    private const array FORUM_HOSTS = ['reddit.com', 'quora.com', 'stackexchange.com', 'stackoverflow.com', 'answers.microsoft.com', 'tripadvisor.com/showtopic', 'community.', 'forum.', 'forums.', 'discourse.', 'boards.'];
    private const array VIDEO_HOSTS = ['youtube.com', 'youtu.be', 'vimeo.com', 'tiktok.com', 'dailymotion.com'];
    private const array REFERENCE_HOSTS = ['wikipedia.org', 'britannica.com', 'merriam-webster.com', 'dictionary.com', 'dictionary.cambridge.org', 'collinsdictionary.com', 'thefreedictionary.com', 'vocabulary.com', 'wiktionary.org'];
    private const array LOCAL_HOSTS = ['yelp.', 'yellowpages.', 'tripadvisor.', 'angi.com', 'thumbtack.com', 'houzz.', 'checkatrade.com', 'trustpilot.com', 'bbb.org', 'google.com/maps', 'foursquare.com'];
    private const array ARTICLE_HOSTS = ['medium.com', 'substack.com', 'dev.to', 'hashnode.dev', 'blogspot.com', 'linkedin.com/pulse', 'hubpages.com'];
    private const array MARKETPLACES = ['amazon.', 'ebay.', 'etsy.com', 'walmart.com', 'aliexpress.com', 'target.com', 'bestbuy.com', 'temu.com'];

    private function __construct() {}

    public static function classify(string $url, ?string $title = null): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = (string) preg_replace('/^(www\d?|m)\./', '', $host);
        $path = strtolower(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $query = strtolower((string) parse_url($url, PHP_URL_QUERY));
        $site = $host . $path;
        $title = mb_strtolower(trim((string) $title));

        if (self::hostIn($host, self::VIDEO_HOSTS)) {
            return self::VIDEO;
        }
        if (self::hostIn($site, self::FORUM_HOSTS) || preg_match('#/(forums?|community|threads?|topic|discussions?|questions)/#', $path) === 1 || preg_match('#^/t/[^/]+/\d+#', $path) === 1) {
            return self::FORUM;
        }
        if (self::hostIn($host, self::REFERENCE_HOSTS)) {
            return self::REFERENCE;
        }
        if (self::hostIn($site, self::LOCAL_HOSTS)) {
            return self::LOCAL;
        }
        if (self::hostIn($site, self::ARTICLE_HOSTS)) {
            return self::ARTICLE;
        }
        if (self::hostIn($host, self::MARKETPLACES)) {
            return preg_match('#/(dp|gp/product|itm|listing|ip)/#', $path) === 1 ? self::PRODUCT : self::CATEGORY;
        }
        if (preg_match('/\b(calculator|checker|generator|converter|estimator|analy[sz]er|validator|simulator|(word|character|letter|calorie|syllable) counter)s?\b/u', $title . ' ' . str_replace(['-', '_', '/'], ' ', $path)) === 1) {
            return self::TOOL;
        }
        if (preg_match('#(^|/)(products?|p|item|items|dp|sku)/[^/]+#', $path) === 1 || preg_match('/\b(buy now|add to cart|in stock|sku)\b/u', $title) === 1) {
            return self::PRODUCT;
        }
        if (preg_match('#/(collections?|categor(y|ies)|product-(categor(y|ies)|tags?)|c|catalog|catalogue|department|shop|store|range|browse)(/|$)#', $path) === 1 || str_contains($query, 'category=') || preg_match('/^(shop|buy) /u', $title) === 1) {
            return self::CATEGORY;
        }
        if (preg_match('/\b(best|top \d+|\d+ best|reviews?|reviewed|vs\.?|versus|compared|comparison|alternatives?|ranked|tested)\b/u', $title) === 1 || preg_match('#/(reviews?|best-|compare|comparison)|-vs-|/vs/|-review|-alternatives#', $path) === 1) {
            return self::REVIEW;
        }
        if (preg_match('#/(blogs?|news|articles?|guides?|learn|resources?|insights|posts?|magazine|journal|academy|how-to|what-is|tips|library)(/|-)#', $path) === 1 || preg_match('#/\d{4}/\d{2}/#', $path) === 1
            || preg_match('/^(how to|what is|what are|why|when|guide|the complete|a beginner|beginner\'s|\d+ (ways|tips|ideas|examples|steps|reasons))\b/u', $title) === 1) {
            return self::ARTICLE;
        }
        if ($path === '' || $path === '/' || preg_match('#^/[a-z]{2}(-[a-z]{2})?/?$#', $path) === 1) {
            return self::HOME;
        }
        if (preg_match('#/(services?|solutions?|pricing|plans|features|hire|contact|quote|locations?|areas?)(/|$)#', $path) === 1 || preg_match('/\b(services|agency|company|contractors?|near you|free quote|book (a|an|now))\b/u', $title) === 1) {
            return self::SERVICE;
        }

        return self::OTHER;
    }

    public static function label(string $format): string
    {
        return match ($format) {
            self::PRODUCT => 'product page',
            self::CATEGORY => 'category or listing page',
            self::REVIEW => 'review or comparison page',
            self::ARTICLE => 'article or guide',
            self::TOOL => 'free tool',
            self::FORUM => 'forum or Q&A thread',
            self::VIDEO => 'video',
            self::REFERENCE => 'reference or dictionary entry',
            self::LOCAL => 'local directory listing',
            self::SERVICE => 'service or landing page',
            self::HOME => 'home page',
            default => 'unclassified page',
        };
    }

    /** The label with "a" or "an". */
    public static function withArticle(string $format): string
    {
        $label = self::label($format);

        return (preg_match('/^[aeiou]/', $label) === 1 ? 'an ' : 'a ') . $label;
    }

    public static function plural(string $format): string
    {
        return match ($format) {
            self::PRODUCT => 'product pages',
            self::CATEGORY => 'category or listing pages',
            self::REVIEW => 'review or comparison pages',
            self::ARTICLE => 'articles and guides',
            self::TOOL => 'free tools',
            self::FORUM => 'forum or Q&A threads',
            self::VIDEO => 'videos',
            self::REFERENCE => 'reference or dictionary entries',
            self::LOCAL => 'local directory listings',
            self::SERVICE => 'service or landing pages',
            self::HOME => 'home pages',
            default => 'unclassified pages',
        };
    }

    /** What to build to compete with a results page dominated by $format. */
    public static function action(string $format): string
    {
        return match ($format) {
            self::PRODUCT => 'a product page with price, availability, specifications and reviews',
            self::CATEGORY => 'a category page that lists the matching products with filters and a short introduction',
            self::REVIEW => 'a comparison or review page with first-hand testing, clear criteria and a verdict',
            self::ARTICLE => 'an in-depth guide that answers the question and its follow-up questions',
            self::TOOL => 'a free interactive tool that does the job on the page',
            self::FORUM => 'a detailed, first-hand answer page; forum threads ranking is a sign the existing pages answer the question poorly',
            self::VIDEO => 'a video plus a page that embeds it with a written summary',
            self::REFERENCE => 'a concise reference or definition page; expect few clicks, since the results page often answers it',
            self::LOCAL => 'a local landing page plus a complete Business Profile and directory listings',
            self::SERVICE => 'a service landing page with the offer, proof, prices or quote and a clear call to action',
            self::HOME => 'a strong home page for the brand; these results are usually navigational',
            default => 'a page that matches what the top results offer',
        };
    }

    /**
     * Formats that satisfy the same need: an article and a reference entry
     * both explain, a home page and a service page both present an offer.
     */
    public static function compatible(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        foreach ([[self::ARTICLE, self::REFERENCE], [self::HOME, self::SERVICE], [self::ARTICLE, self::FORUM], [self::ARTICLE, self::VIDEO]] as $pair) {
            if (in_array($a, $pair, true) && in_array($b, $pair, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "example.com" matches the host and its subdomains, "yelp." any host
     * whose name starts with that label (yelp.com, yelp.co.uk), and a
     * pattern with a slash the start of host + path.
     *
     * @param list<string> $patterns
     */
    private static function hostIn(string $site, array $patterns): bool
    {
        $host = explode('/', $site, 2)[0];
        foreach ($patterns as $pattern) {
            $match = match (true) {
                str_contains($pattern, '/') => str_starts_with($site, $pattern) || str_contains($site, '.' . $pattern),
                str_ends_with($pattern, '.') => str_starts_with($host, $pattern) || str_contains($host, '.' . $pattern),
                default => $host === $pattern || str_ends_with($host, '.' . $pattern),
            };
            if ($match) {
                return true;
            }
        }

        return false;
    }
}

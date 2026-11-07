<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Features;

/**
 * Features an administrator can turn off with DISABLED_FEATURES (ADR-052).
 * All are on by default. A feature that is off stops new work only: imported
 * data, history and settings stay readable (ADR-009).
 */
enum Feature: string
{
    /** Search Console imports (daily sync and backfill). */
    case SearchConsole = 'search_console';
    /** Bing Webmaster Tools imports and IndexNow submissions. */
    case BingWebmaster = 'bing_webmaster';
    /** Rank checks, scheduled and on demand. */
    case RankTracking = 'rank_tracking';
    /** Keyword metrics from DataForSEO. */
    case KeywordMetrics = 'keyword_metrics';
    /** Adding competitors (their positions come from rank checks). */
    case Competitors = 'competitors';
    /** Opportunity analysis runs. */
    case Opportunities = 'opportunities';
    /** Site audits (crawling the project's own site). */
    case SiteAudit = 'site_audit';
    /** Backlink data (DataForSEO Backlinks). */
    case Backlinks = 'backlinks';
    /** The MCP endpoint for AI clients. */
    case Mcp = 'mcp';

    public function label(): string
    {
        return match ($this) {
            self::SearchConsole => 'Search Console imports',
            self::BingWebmaster => 'Bing Webmaster Tools and IndexNow',
            self::RankTracking => 'Rank tracking',
            self::KeywordMetrics => 'Keyword metrics',
            self::Competitors => 'Competitor tracking',
            self::Opportunities => 'Opportunity analysis',
            self::SiteAudit => 'Site audit',
            self::Backlinks => 'Backlinks',
            self::Mcp => 'AI access (MCP)',
        };
    }
}

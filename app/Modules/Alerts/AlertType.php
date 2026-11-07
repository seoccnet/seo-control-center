<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

/**
 * The events that trigger an instant alert e-mail.
 */
enum AlertType: string
{
    case RankingDrop = 'ranking_drop';
    case TrafficDrop = 'traffic_drop';
    case AuditErrors = 'audit_errors';
    case LostLinks = 'lost_links';
    case Budget = 'budget';
    case IndexingChange = 'indexing_change';
    case PageChange = 'page_change';
    case SerpChange = 'serp_change';

    public function label(): string
    {
        return match ($this) {
            self::RankingDrop => 'Keywords dropping below your cutoff',
            self::TrafficDrop => 'A sharp drop in clicks',
            self::AuditErrors => 'New site audit errors',
            self::LostLinks => 'Strong backlinks lost',
            self::Budget => 'DataForSEO budget or balance running low',
            self::IndexingChange => 'Google indexing or canonical changed',
            self::PageChange => 'Important page SEO settings changed',
            self::SerpChange => 'Results pages of tracked keywords changed',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::RankingDrop => 'After a rank check, when keywords leave the project’s configured top-position group (default: top 10).',
            self::TrafficDrop => 'When seven-day Search Console clicks fall beyond the project’s threshold (default: 30%, with at least 100 previous clicks).',
            self::AuditErrors => 'When a site audit finds more errors than the previous one, such as broken pages or server errors.',
            self::LostLinks => 'When a lookup finds lost domains meeting the project’s minimum domain rank (default: 30).',
            self::Budget => 'When this month\'s DataForSEO spending will reach a budget limit or the account balance runs out within a week. Owners and admins only.',
            self::IndexingChange => 'After two successful inspections report different indexing verdicts or Google-selected canonicals. This reports Google’s stored evidence, not a live test or confirmed SEO diagnosis.',
            self::PageChange => 'After two complete page checks detect a change in HTTP status, canonical or noindex. Review the before/after evidence; the exact edit time is unknown.',
            self::SerpChange => 'After a rank check, when an AI Overview appears for a keyword where you rank in the top 10, you leave the top 10, or a tracked competitor enters the top 10 above you. Compared with the previous check on the same device.',
        };
    }
}

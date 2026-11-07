<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

enum OpportunityType: string
{
    case QuickWin = 'quick_win';
    case LowCtr = 'low_ctr';
    case RankingDecline = 'ranking_decline';
    case TrafficDecline = 'traffic_decline';
    case CompetitorGap = 'competitor_gap';
    case ContentGap = 'content_gap';
    case SiteIssue = 'site_issue';
    case KeywordGap = 'keyword_gap';
    case LinkBuilding = 'link_building';
    case AiVisibility = 'ai_visibility';
    case LocalSeo = 'local_seo';
    case Cannibalization = 'cannibalization';
    case Indexing = 'indexing';
    case InternalLinks = 'internal_links';
    case ClickErosion = 'click_erosion';
    case FormatMismatch = 'format_mismatch';
    case ToolIdea = 'tool_idea';
    case ContentRefresh = 'content_refresh';

    public function label(): string
    {
        return match ($this) {
            self::QuickWin => 'Quick win',
            self::LowCtr => 'Low click-through rate',
            self::RankingDecline => 'Ranking decline',
            self::TrafficDecline => 'Traffic decline',
            self::CompetitorGap => 'Competitor gap',
            self::ContentGap => 'Content gap',
            self::SiteIssue => 'Technical issue',
            self::KeywordGap => 'Keyword gap',
            self::LinkBuilding => 'Link building',
            self::AiVisibility => 'AI visibility',
            self::LocalSeo => 'Local SEO',
            self::Cannibalization => 'Cannibalization',
            self::Indexing => 'Indexing concern',
            self::InternalLinks => 'Internal links',
            self::ClickErosion => 'Clicks falling, ranking stable',
            self::FormatMismatch => 'Format mismatch',
            self::ToolIdea => 'Free tool idea',
            self::ContentRefresh => 'Refresh aging content',
        };
    }
}

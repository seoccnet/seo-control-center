<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Mcp;

/**
 * MCP scopes (MCP.md -> Scopes). Read scopes cover stored data; write
 * scopes (ADR-036) cover non-billable, non-destructive changes and are
 * never granted unless the token's creator ticks them.
 */
enum McpScope: string
{
    case Projects = 'projects:read';
    case Search = 'search:read';
    case Keywords = 'keywords:read';
    case Rankings = 'rankings:read';
    case Competitors = 'competitors:read';
    case Opportunities = 'opportunities:read';
    case Pages = 'pages:read';
    case AnnotationsWrite = 'annotations:write';
    case TasksWrite = 'tasks:write';
    case BriefsWrite = 'briefs:write';
    case OpportunitiesWrite = 'opportunities:write';
    case WatchesWrite = 'watches:write';
    case KeywordsWrite = 'keywords:write';
    case ClustersWrite = 'clusters:write';
    case InvestigationsWrite = 'investigations:write';

    public function label(): string
    {
        return match ($this) {
            self::Projects => 'Projects',
            self::Search => 'Search Console performance',
            self::Keywords => 'Keywords and metrics',
            self::Rankings => 'Rankings',
            self::Competitors => 'Competitors',
            self::Opportunities => 'Opportunities',
            self::Pages => 'Pages and their SEO evidence',
            self::AnnotationsWrite => 'Add and edit change notes',
            self::TasksWrite => 'Assign and update team tasks',
            self::BriefsWrite => 'Create and edit content briefs',
            self::OpportunitiesWrite => 'Change opportunity status',
            self::WatchesWrite => 'Start and stop page watches',
            self::KeywordsWrite => 'Mark keywords relevant or not relevant',
            self::ClustersWrite => 'Set which page owns a topic',
            self::InvestigationsWrite => 'Keep research notebooks (investigations)',
        };
    }

    public function isWrite(): bool
    {
        return str_ends_with($this->value, ':write');
    }

    /** @return list<self> */
    public static function reads(): array
    {
        return array_values(array_filter(self::cases(), static fn(self $s): bool => !$s->isWrite()));
    }

    /** @return list<self> */
    public static function writes(): array
    {
        return array_values(array_filter(self::cases(), static fn(self $s): bool => $s->isWrite()));
    }
}

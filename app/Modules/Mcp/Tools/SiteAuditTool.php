<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\SiteAudit;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

final readonly class SiteAuditTool implements McpTool
{
    public function __construct(private SiteAuditRepository $audits) {}

    public function name(): string
    {
        return 'site_audit';
    }

    public function title(): string
    {
        return 'Site audit';
    }

    public function description(): string
    {
        return 'The latest completed site audit of the project: health score (0-100), pages checked, and the number of pages per issue type (broken pages, redirects, missing titles and descriptions, duplicates, slow pages and more) with severity and advice. With `issue_type`, also the pages that have that issue, each with its current status code, redirect target, title, meta description, H1, canonical, noindex and word count as crawled, so exact replacements can be written per URL. Reads the stored crawl; it does not start an audit.';
    }

    public function scope(): McpScope
    {
        return McpScope::Opportunities;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'issue_type' => ['type' => 'string', 'enum' => array_map(static fn(IssueType $t): string => $t->value, IssueType::cases()), 'description' => 'List the pages with this issue.'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
            'audit_id' => ['type' => 'string', 'description' => 'Optional completed audit public ID from audit_history. Omit for latest.'],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $completed = array_values(array_filter($this->audits->history($project->id, 10), static fn(SiteAudit $a): bool => $a->isCompleted()));
        $audit = $completed[0] ?? null;
        if (isset($arguments['audit_id'])) {
            $audit = array_find($completed, static fn(SiteAudit $a): bool => $a->publicId === $arguments['audit_id']);
            if ($audit === null) {
                throw new \SeoControlCenter\Modules\Mcp\Server\ToolError('Audit not found in the recent completed history of this project.');
            }
        }
        if ($audit === null) {
            return ['project_id' => $project->publicId, 'audit' => null, 'note' => 'No completed site audit yet.'];
        }
        $selectedIndex = array_find_key($completed, static fn(SiteAudit $a): bool => $a->publicId === $audit->publicId);
        $previous = $selectedIndex !== null ? ($completed[$selectedIndex + 1] ?? null) : null;
        $before = $previous !== null ? $this->audits->issueCounts($previous->id) : [];
        $issues = [];
        foreach ($this->audits->issueCounts($audit->id) as $value => $pages) {
            $type = IssueType::tryFrom($value);
            if ($type !== null) {
                $issues[] = [
                    'issue_type' => $type->value,
                    'label' => $type->label(),
                    'severity' => $type->severity(),
                    'pages' => $pages,
                    'pages_in_previous_audit' => $previous !== null ? ($before[$value] ?? 0) : null,
                    'advice' => $type->advice(),
                ];
            }
        }
        usort($issues, static fn(array $a, array $b): int => [array_search($a['severity'], ['error', 'warning', 'notice'], true), -$a['pages']] <=> [array_search($b['severity'], ['error', 'warning', 'notice'], true), -$b['pages']]);

        $result = [
            'project_id' => $project->publicId,
            'audit_history' => array_map(static fn(SiteAudit $a): array => ['audit_id' => $a->publicId, 'finished_at' => $a->finishedAt, 'health_score' => $a->healthScore, 'pages_checked' => $a->pagesCrawled], $completed),
            'history_limit' => 10,
            'audit' => [
                'finished_at' => $audit->finishedAt,
                'health_score' => $audit->healthScore,
                'health_score_previous' => $previous?->healthScore,
                'pages_checked' => $audit->pagesCrawled,
                'page_limit' => $audit->maxPages,
                'errors' => $audit->errors,
                'warnings' => $audit->warnings,
                'notices' => $audit->notices,
            ],
            'issues' => $issues,
        ];
        if (isset($arguments['issue_type'])) {
            $type = IssueType::from(ToolContext::choice($arguments, 'issue_type', '', array_map(static fn(IssueType $t): string => $t->value, IssueType::cases())));
            $offset = ToolContext::int($arguments, 'offset', 0, 0, 100000);
            $limit = ToolContext::int($arguments, 'limit', 50, 1, 200);
            $rows = $this->audits->issues($audit->id, $type, $offset, $limit);
            $result['offset'] = $offset;
            $result['total'] = $this->audits->issueCounts($audit->id)[$type->value] ?? 0;
            $result['next_offset'] = $offset + count($rows) < $result['total'] ? $offset + count($rows) : null;
            $pages = $this->audits->pagesById($audit->id, array_values(array_unique(array_column($rows, 'pageId'))));
            $result['pages'] = array_map(function (array $row) use ($type, $audit, $pages): array {
                $page = $pages[$row['pageId']] ?? null;

                return [
                    'url' => $row['url'],
                    'detail' => $row['detail'],
                    'status' => $page?->statusCode,
                    'redirect_to' => $page?->redirectTo,
                    'title' => $page?->title,
                    'meta_description' => $page?->description,
                    'h1' => $page?->h1,
                    'canonical' => $page?->canonical,
                    'noindex' => $page?->noindex,
                    'word_count' => $page?->wordCount,
                    'linked_from' => in_array($type, [IssueType::BrokenPage, IssueType::ServerError, IssueType::Unreachable, IssueType::HttpUrl, IssueType::Redirect, IssueType::RedirectChain, IssueType::BlockedByRobots], true)
                        ? $this->audits->linkSources($audit->id, $row['pageId'], 3)
                        : [],
                ];
            }, $rows);
        }

        return $result;
    }
}

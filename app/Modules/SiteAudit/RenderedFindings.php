<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Modules\Research\ResearchResult;

/** Stored browser evidence only; lab findings never become CrUX measurements. */
final class RenderedFindings
{
    /** @return list<array{type: string, label: string, severity: string, advice: string, pages: int, examples: list<string>, effort: int}> */
    public static function issues(?ResearchResult $result): array
    {
        if ($result?->fetchedAt === null) {
            return [];
        }
        $groups = [];
        foreach ($result->items as $row) {
            $url = $row['url'] ?? null;
            if (!is_string($url)) {
                continue;
            }
            $findings = is_array($row['findings'] ?? null) ? $row['findings'] : [];
            foreach (['Missing title' => IssueType::MissingTitle, 'Missing description' => IssueType::MissingDescription, 'Missing H1' => IssueType::MissingH1] as $label => $type) {
                if (in_array($label, $findings, true)) {
                    $groups[$type->value][$url] = true;
                }
            }
            foreach (is_array($row['browser_resources'] ?? null) ? $row['browser_resources'] : [] as $resource) {
                if (is_array($resource) && in_array($resource['status'] ?? null, [404, 410], true)) {
                    $groups[IssueType::BrokenAsset->value][$url] = true;
                }
            }
        }
        $issues = [];
        foreach ($groups as $value => $urls) {
            $type = IssueType::from($value);
            $issues[] = ['type' => 'rendered_' . $value, 'label' => 'Browser: ' . $type->label(), 'severity' => $type->severity(),
                'advice' => $type === IssueType::BrokenAsset ? 'A browser-loaded resource returned 404 or 410. Repair its reference or restore the file; compare the saved resource evidence.' : $type->advice(),
                'pages' => count($urls), 'examples' => array_slice(array_keys($urls), 0, 3), 'effort' => \SeoControlCenter\Domain\Opportunities\Opportunity::EFFORT_LOW];
        }
        return $issues;
    }
}

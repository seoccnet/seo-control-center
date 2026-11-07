<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

final class ResourceIssues
{
    /** @param list<array{pageId: int, url: string, kind: string, state: string, status: ?int}> $refs
     * @return list<array{pageId: int, type: IssueType, detail: ?string}>
     */
    public static function detect(array $refs): array
    {
        $issues = [];
        foreach ($refs as $ref) {
            $types = [];
            if ($ref['kind'] === 'image_alt') {
                $types[] = IssueType::MissingImageAlt;
            }
            if ($ref['kind'] !== 'external' && str_starts_with($ref['url'], 'http://')) {
                $types[] = IssueType::MixedContent;
            }
            if (in_array($ref['status'], [404, 410], true)) {
                $types[] = $ref['kind'] === 'external' ? IssueType::BrokenExternalLink : IssueType::BrokenAsset;
            }
            if ($ref['state'] === 'failed' || ($ref['status'] !== null && $ref['status'] >= 500)) {
                $types[] = IssueType::ResourceUnavailable;
            }
            foreach ($types as $type) {
                $key = $ref['pageId'] . ':' . $type->value;
                $issues[$key] ??= ['pageId' => $ref['pageId'], 'type' => $type, 'detail' => ''];
                $line = $ref['kind'] . ': ' . $ref['url'] . ($ref['status'] !== null ? ' (HTTP ' . $ref['status'] . ')' : '');
                $issues[$key]['detail'] = mb_substr($issues[$key]['detail'] . ($issues[$key]['detail'] !== '' ? ' · ' : '') . $line, 0, 500);
            }
        }
        return array_values($issues);
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

final class ResourceIssues
{
    /** Image files over this size are reported (1.5.0). */
    public const int LARGE_IMAGE = 300 * 1024;
    /** JPEG, PNG and GIF files over this size are suggested a modern format. */
    public const int LEGACY_IMAGE = 100 * 1024;
    private const array LEGACY_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/bmp'];

    /** @param list<array{pageId: int, url: string, kind: string, state: string, status: ?int, bytes?: ?int, type?: ?string}> $refs
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
            $bytes = $ref['bytes'] ?? null;
            $isImage = in_array($ref['kind'], ['image', 'image_alt'], true);
            if ($isImage && $bytes !== null && $ref['status'] !== null && $ref['status'] < 300) {
                $mime = $ref['type'] ?? null;
                $extension = strtolower((string) pathinfo((string) parse_url($ref['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
                if ($bytes > self::LARGE_IMAGE) {
                    $types[] = IssueType::LargeImage;
                }
                if ($bytes > self::LEGACY_IMAGE && (in_array($mime, self::LEGACY_TYPES, true) || ($mime === null && in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp'], true)))) {
                    $types[] = IssueType::LegacyImageFormat;
                }
            }
            foreach ($types as $type) {
                $key = $ref['pageId'] . ':' . $type->value;
                $issues[$key] ??= ['pageId' => $ref['pageId'], 'type' => $type, 'detail' => ''];
                $line = in_array($type, [IssueType::LargeImage, IssueType::LegacyImageFormat], true)
                    ? $ref['url'] . ' (' . number_format((int) ($ref['bytes'] ?? 0) / 1024) . ' KB' . (($ref['type'] ?? null) !== null ? ', ' . $ref['type'] : '') . ')'
                    : $ref['kind'] . ': ' . $ref['url'] . ($ref['status'] !== null ? ' (HTTP ' . $ref['status'] . ')' : '');
                $issues[$key]['detail'] = mb_substr($issues[$key]['detail'] . ($issues[$key]['detail'] !== '' ? ' · ' : '') . $line, 0, 500);
            }
        }
        return array_values($issues);
    }
}

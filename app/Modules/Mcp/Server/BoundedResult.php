<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Server;

/** Valid JSON with explicit omissions; never cut an encoded document. */
final class BoundedResult
{
    /** List fields that tools page with offset/next_offset. */
    private const array PAGED = ['rows', 'keywords', 'opportunities', 'links', 'domains', 'pages', 'landing_pages', 'ideas', 'plans', 'outcomes', 'profiles', 'competitors', 'projects'];

    /** @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function limit(array $data, int $bytes = 16000): array
    {
        if (strlen(self::json($data)) <= $bytes) {
            return $data;
        }
        if (array_is_list($data)) {
            $data = ['rows' => $data, 'total' => count($data)];
        }
        $changes = [];
        $target = max(512, $bytes - 2048);
        for ($attempt = 0; strlen(self::json($data)) > $target && $attempt < 100; $attempt++) {
            $path = [];
            $largest = 0;
            self::candidate($data, [], $path, $largest);
            if ($path === []) {
                return ['result_limits' => ['truncated' => true, 'message' => 'Result exceeds the response limit. Request fewer rows or an exact URL/issue/date filter.'], 'project_id' => $data['project_id'] ?? null];
            }
            $value = &$data;
            foreach ($path as $key) {
                $value = &$value[$key];
            }
            $label = implode('.', $path);
            if (is_array($value)) {
                $original = $changes[$label]['original_count'] ?? count($value);
                $value = array_slice($value, 0, max(0, intdiv(count($value), 2)));
                $changes[$label] = ['original_count' => $original, 'returned_count' => count($value)];
            } elseif (is_string($value)) {
                $value = mb_substr($value, 0, max(0, intdiv(mb_strlen($value), 2)));
                $changes[$label] = ['text_truncated' => true];
            }
            unset($value);
        }
        if ($changes !== []) {
            $pageable = [];
            $fixed = [];
            foreach ($changes as $label => $change) {
                $segments = explode('.', $label);
                $field = array_pop($segments);
                $parent = &$data;
                foreach ($segments as $segment) {
                    if (!isset($parent[$segment]) || !is_array($parent[$segment])) {
                        unset($parent);
                        continue 2;
                    }
                    $parent = &$parent[$segment];
                }
                if (isset($change['returned_count']) && in_array($field, self::PAGED, true) && is_int($parent['offset'] ?? null)) {
                    $parent['next_offset'] = $change['returned_count'] > 0 ? $parent['offset'] + $change['returned_count'] : null;
                    $pageable[] = $label;
                } elseif ($field === 'text' && ($change['text_truncated'] ?? false) && is_int($parent['offset'] ?? null) && is_int($parent['total_characters'] ?? null) && is_string($parent['text'] ?? null)) {
                    $next = $parent['offset'] + mb_strlen($parent['text']);
                    $parent['next_offset'] = $next < $parent['total_characters'] ? $next : null;
                    $pageable[] = $label;
                } else {
                    $fixed[] = $label;
                }
                unset($parent);
            }
            $message = ['Not every record fit in this response; do not repeat the same request.'];
            if ($pageable !== []) {
                $message[] = 'To continue ' . implode(', ', $pageable) . ', call again with offset = next_offset (it counts the records actually returned, not the requested limit).';
            }
            if ($fixed !== []) {
                $message[] = 'This tool cannot page ' . implode(', ', $fixed) . ': narrow the filters, choose an exact ID or URL, or lower limit.';
            }
            $data['result_limits'] = ['truncated' => true, 'fields' => $changes, 'message' => implode(' ', $message)];
        }
        if (strlen(self::json($data)) > $bytes) {
            return ['project_id' => $data['project_id'] ?? null, 'result_limits' => ['truncated' => true, 'message' => 'Result and omission metadata exceed the limit. Request a narrower URL, issue or date filter. No rows returned.']];
        }

        return $data;
    }

    /** @param array<array-key, mixed> $data */
    public static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<array-key, mixed> $data
     * @param list<int|string> $prefix
     * @param list<int|string> $path
     */
    private static function candidate(array $data, array $prefix, array &$path, int &$largest): void
    {
        foreach ($data as $key => $value) {
            if ($key === 'result_limits') {
                continue;
            }
            $next = [...$prefix, $key];
            if (is_array($value) && array_is_list($value) && $value !== []) {
                $size = strlen(self::json($value));
                if ($size > $largest) {
                    $path = $next;
                    $largest = $size;
                }
            } elseif (is_array($value)) {
                self::candidate($value, $next, $path, $largest);
            } elseif (is_string($value) && strlen($value) > 2000 && strlen($value) > $largest) {
                $path = $next;
                $largest = strlen($value);
            }
        }
    }
}

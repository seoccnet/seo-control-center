<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Modules\SiteAudit\PageResult;

/** Only complete successful HTTP observations become comparison evidence. */
final readonly class PageSnapshot
{
    /** @param array<string, int|string|bool|null> $values */
    public function __construct(public array $values) {}

    public static function fromResult(PageResult $result): ?self
    {
        if ($result->state !== 'done' || $result->error !== null || $result->statusCode === null || $result->truncated) {
            return null;
        }
        return new self([
            'status' => $result->statusCode,
            'title' => $result->page?->title,
            'description' => $result->page?->description,
            'h1s' => $result->page === null ? null : implode("\n", $result->page->h1s),
            'h1_count' => $result->page?->h1Count,
            'canonical' => $result->page?->canonical,
            'noindex' => $result->page === null ? null : $result->noindex,
            'html' => $result->page !== null,
        ]);
    }

    public static function decode(string $json): self
    {
        $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($values)) {
            throw new \UnexpectedValueException('Invalid saved page evidence.');
        }
        $safe = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && (is_int($value) || is_string($value) || is_bool($value) || $value === null)) {
                $safe[$key] = $value;
            }
        }
        return new self($safe);
    }

    public function encode(): string
    {
        return json_encode($this->values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, array{before: int|string|bool|null, after: int|string|bool|null}> */
    public function changes(self $after): array
    {
        $changes = [];
        foreach (['status', 'title', 'description', 'h1s', 'h1_count', 'canonical', 'noindex'] as $field) {
            // Missing HTML on redirects/errors is not evidence of removed metadata.
            if (!in_array($field, ['status', 'noindex'], true) && (!($this->values['html'] ?? false) || !($after->values['html'] ?? false))) {
                continue;
            }
            $before = $this->values[$field] ?? null;
            $next = $after->values[$field] ?? null;
            if ($field === 'noindex' && ($before === null || $next === null)) {
                continue;
            }
            if ($before !== $next) {
                $changes[$field] = ['before' => $before, 'after' => $next];
            }
        }
        return $changes;
    }

    public function significant(self $after): bool
    {
        return array_intersect(['status', 'canonical', 'noindex'], array_keys($this->changes($after))) !== [];
    }

    public static function display(int|string|bool|null $value): string
    {
        return is_bool($value) ? ($value ? 'Yes' : 'No') : ($value === null ? 'Not present' : (string) $value);
    }
}

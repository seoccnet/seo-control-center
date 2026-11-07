<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Investigations;

use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Validated investigation fields and items from a form or an MCP call.
 */
final class InvestigationInput
{
    public const int MAX_ITEMS = 300;
    public const int MAX_ITEMS_PER_CALL = 30;

    /**
     * @param array<string, mixed> $data title, question, status, conclusion
     * @return array{title: string, question: ?string, status: string, conclusion: ?string}
     * @throws ValidationException
     */
    public static function fields(array $data): array
    {
        $errors = [];
        $title = self::text($data['title'] ?? null);
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Enter a title of up to 200 characters.';
        }
        $question = self::text($data['question'] ?? null, true);
        if (mb_strlen($question) > 2000) {
            $errors['question'] = 'The question can be at most 2,000 characters.';
        }
        $status = is_string($data['status'] ?? null) && $data['status'] !== '' ? $data['status'] : Investigation::OPEN;
        if (!in_array($status, Investigation::STATUSES, true)) {
            $errors['status'] = 'Choose open, concluded or archived.';
        }
        $conclusion = self::text($data['conclusion'] ?? null, true);
        if (mb_strlen($conclusion) > 5000) {
            $errors['conclusion'] = 'The conclusion can be at most 5,000 characters.';
        }
        if ($status === Investigation::CONCLUDED && $conclusion === '') {
            $errors['conclusion'] = 'Write the conclusion before marking the investigation concluded.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['title' => $title, 'question' => $question !== '' ? $question : null, 'status' => $status, 'conclusion' => $conclusion !== '' ? $conclusion : null];
    }

    /**
     * @param array<string, mixed> $data kind, label, detail, ref_type, ref_id, observed_on
     * @return array{kind: string, ref_type: ?string, ref_id: ?string, label: string, detail: ?string, observed_on: ?string}
     * @throws ValidationException
     */
    public static function item(array $data, string $today): array
    {
        $errors = [];
        $kind = $data['kind'] ?? null;
        if (!in_array($kind, InvestigationItem::KINDS, true)) {
            $errors['kind'] = 'Choose source, check, limitation or note.';
        }
        $label = self::text($data['label'] ?? null);
        if ($label === '' || mb_strlen($label) > 255) {
            $errors['label'] = 'Enter what this entry is, in up to 255 characters.';
        }
        $detail = self::text($data['detail'] ?? null, true);
        if (mb_strlen($detail) > 2000) {
            $errors['detail'] = 'The detail can be at most 2,000 characters.';
        }
        $refType = is_string($data['ref_type'] ?? null) && $data['ref_type'] !== '' ? $data['ref_type'] : null;
        $refId = is_string($data['ref_id'] ?? null) && trim($data['ref_id']) !== '' ? trim($data['ref_id']) : null;
        if ($refType !== null && !in_array($refType, InvestigationItem::REF_TYPES, true)) {
            $errors['ref_type'] = 'Unknown reference type.';
        } elseif (($refType === null) !== ($refId === null)) {
            $errors['ref_id'] = 'A reference needs both its type and its ID or URL.';
        } elseif ($refId !== null && (mb_strlen($refId) > 2048 || ($refType === 'url' || $refType === 'page') && !self::url($refId))) {
            $errors['ref_id'] = 'Enter a full http(s) URL of up to 2,048 characters.';
        }
        $observed = is_string($data['observed_on'] ?? null) && $data['observed_on'] !== '' ? $data['observed_on'] : null;
        if ($observed !== null && (preg_match('/^\d{4}-\d{2}-\d{2}$/', $observed) !== 1 || !checkdate((int) substr($observed, 5, 2), (int) substr($observed, 8, 2), (int) substr($observed, 0, 4)) || $observed > $today)) {
            $errors['observed_on'] = 'Enter the date the evidence was observed (YYYY-MM-DD), not in the future.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return ['kind' => (string) $kind, 'ref_type' => $refType, 'ref_id' => $refId, 'label' => $label, 'detail' => $detail !== '' ? $detail : null, 'observed_on' => $observed];
    }

    private static function text(mixed $value, bool $multiline = false): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = (string) preg_replace($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]+/u', $multiline ? '' : ' ', $value);

        return trim($value);
    }

    private static function url(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }
}

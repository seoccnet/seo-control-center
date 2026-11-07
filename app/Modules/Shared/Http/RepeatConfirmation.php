<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Shared\Http;

use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Security\CsrfMiddleware;

/**
 * "This paid lookup already ran" (ADR-038, D3): after a refused repeat,
 * the page shows the reason and one button that sends the same form again
 * with run_again=1. The form fields are the user's own submission, kept in
 * the session flash; the layout renders them as hidden fields, escaped.
 */
final class RepeatConfirmation
{
    public const string FLASH = 'repeat';
    public const string FIELD = 'run_again';

    public static function flash(ServerRequestInterface $request, string $message): void
    {
        $fields = [];
        foreach (WebContext::body($request) as $name => $value) {
            if (is_string($value) && $name !== CsrfMiddleware::FIELD && $name !== self::FIELD && strlen($value) <= 5000) {
                $fields[(string) $name] = $value;
            }
        }
        WebContext::session($request)->flash(self::FLASH, (string) json_encode(['message' => $message, 'action' => $request->getUri()->getPath(), 'fields' => $fields], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function requested(ServerRequestInterface $request): bool
    {
        return (WebContext::body($request)[self::FIELD] ?? null) === '1';
    }

    /**
     * @return array{message: string, action: string, fields: array<string, string>}|null
     */
    public static function read(?string $flash): ?array
    {
        $data = $flash !== null ? json_decode($flash, true) : null;
        if (!is_array($data) || !is_string($data['message'] ?? null) || !is_string($data['action'] ?? null) || !str_starts_with($data['action'], '/') || str_starts_with($data['action'], '//') || !is_array($data['fields'] ?? null)) {
            return null;
        }

        return ['message' => $data['message'], 'action' => $data['action'], 'fields' => array_filter($data['fields'], is_string(...))];
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\View;

use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Security\CsrfMiddleware;
use SeoControlCenter\Core\Session\Session;
use Stringable;

/**
 * Helper object available as `$view` inside templates.
 */
final class Template
{
    /** @var array{0: string, 1: array<string, mixed>}|null */
    private ?array $layout = null;

    /**
     * @param (\Closure(string, array<string, mixed>, Template): string)|null $partials renders another template
     */
    public function __construct(
        private readonly string $cspNonce,
        private readonly ?ServerRequestInterface $request = null,
        private readonly ?\Closure $partials = null,
    ) {}

    /**
     * Renders a reusable part (resources/views/partials/…) with its own data.
     * The result is trusted HTML.
     *
     * @param array<string, mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        return $this->partials !== null ? ($this->partials)($template, $data, $this) : '';
    }

    /**
     * An inline stroke icon (decorative: hidden from screen readers).
     */
    public function icon(string $name): string
    {
        return Icons::svg($name);
    }

    /**
     * Escapes a value for HTML text and quoted attribute contexts.
     */
    public function e(string|int|float|bool|Stringable|null $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * The per-request CSP nonce for inline <script nonce="…"> tags.
     */
    public function nonce(): string
    {
        return $this->e($this->cspNonce);
    }

    /**
     * Hidden CSRF input for forms that change state.
     */
    public function csrfField(): string
    {
        $session = $this->request?->getAttribute(RequestAttribute::SESSION);
        if (!$session instanceof Session) {
            return '';
        }

        return sprintf('<input type="hidden" name="%s" value="%s">', CsrfMiddleware::FIELD, $this->e($session->csrfToken()));
    }

    /**
     * Attributes for a form control whose field has a validation error:
     * marks it invalid and points it at the message from fieldError().
     *
     * @param array<string, string> $errors
     */
    public function invalid(array $errors, string $field, string $id): string
    {
        return isset($errors[$field]) ? sprintf(' aria-invalid="true" aria-describedby="%s-error"', $this->e($id)) : '';
    }

    /**
     * The validation message of a field, referenced by the control through invalid().
     *
     * @param array<string, string> $errors
     */
    public function fieldError(array $errors, string $field, string $id): string
    {
        return isset($errors[$field]) ? sprintf('<p id="%s-error" class="field-error">%s</p>', $this->e($id), $this->e($errors[$field])) : '';
    }

    /**
     * A message flashed by the previous request (e.g. "Project created.").
     */
    public function flash(string $key): ?string
    {
        $session = $this->request?->getAttribute(RequestAttribute::SESSION);

        return $session instanceof Session ? $session->getFlash($key) : null;
    }

    /**
     * Reads a request attribute (current user, workspace, …) for layouts.
     */
    public function attribute(string $name): mixed
    {
        return $this->request?->getAttribute($name);
    }

    public function path(): string
    {
        return $this->request?->getUri()->getPath() ?? '/';
    }

    /**
     * Wraps the current template in a layout. The layout receives the
     * rendered template as `$content`.
     *
     * @param array<string, mixed> $data
     */
    public function extend(string $layout, array $data = []): void
    {
        $this->layout = [$layout, $data];
    }

    /**
     * @internal used by ViewRenderer
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function takeLayout(): ?array
    {
        $layout = $this->layout;
        $this->layout = null;

        return $layout;
    }
}

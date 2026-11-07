<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\View;

use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\RequestAttribute;
use Throwable;

/**
 * Renders plain PHP templates from resources/views.
 *
 * Templates receive their data as variables plus `$view` (a Template helper
 * for escaping, CSP nonce and layouts). All output must be escaped with
 * `$view->e()` unless it is trusted HTML produced by the application.
 */
final readonly class ViewRenderer
{
    /**
     * @param array<string, mixed> $shared values available to every template
     */
    public function __construct(
        private string $directory,
        private array $shared = [],
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], ?ServerRequestInterface $request = null): string
    {
        $nonce = $request?->getAttribute(RequestAttribute::CSP_NONCE);
        // Partials share the helper (nonce, request) but never set a layout.
        $helper = new Template(is_string($nonce) ? $nonce : '', $request, fn(string $template, array $data, Template $view): string => $this->renderFile($template, [...$this->shared, ...$data], $view));

        $content = $this->renderFile($template, [...$this->shared, ...$data], $helper);

        // Layouts may themselves extend other layouts.
        $guard = 0;
        while (($layout = $helper->takeLayout()) !== null) {
            if (++$guard > 5) {
                throw new InvalidArgumentException('Too many nested layouts.');
            }
            [$layoutName, $layoutData] = $layout;
            $content = $this->renderFile($layoutName, [...$this->shared, ...$data, ...$layoutData, 'content' => $content], $helper);
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderFile(string $template, array $data, Template $view): string
    {
        $file = $this->resolve($template);

        $render = static function (string $__file, array $__data, Template $view): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        };

        $level = ob_get_level();
        ob_start();
        try {
            $render($file, $data, $view);

            return (string) ob_get_clean();
        } catch (Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
    }

    private function resolve(string $template): string
    {
        if (preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+)*$#D', $template) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid template name "%s".', $template));
        }
        $file = rtrim($this->directory, '/\\') . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new InvalidArgumentException(sprintf('Template "%s" not found.', $template));
        }

        return $file;
    }
}

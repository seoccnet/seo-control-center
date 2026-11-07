<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\View;

use Psr\Log\LoggerInterface;

/**
 * Widgets that modules add to the signed-in layout, per slot: `topbar`
 * (next to the theme switch) and `floating` (fixed to the bottom right).
 * Widgets are created lazily; one that fails is left out and logged, so it
 * never breaks a page.
 */
final class LayoutWidgets
{
    public const string TOPBAR = 'topbar';
    public const string FLOATING = 'floating';
    /** Page slots rendered by a content template (the workspace dashboard, a project overview). */
    public const string DASHBOARD = 'dashboard';
    public const string PROJECT_OVERVIEW = 'project-overview';

    /** @var array<string, list<\Closure(): LayoutWidget>> */
    private array $factories = [];

    public function __construct(private readonly ?LoggerInterface $logger = null) {}

    /**
     * @param \Closure(): LayoutWidget $factory
     */
    public function add(string $slot, \Closure $factory): void
    {
        $this->factories[$slot][] = $factory;
    }

    public function render(string $slot, Template $view): string
    {
        $html = '';
        foreach ($this->factories[$slot] ?? [] as $factory) {
            try {
                $html .= $factory()->render($view) ?? '';
            } catch (\Throwable $e) {
                $this->logger?->warning('Layout widget failed', ['slot' => $slot, 'exception' => $e]);
            }
        }

        return $html;
    }
}

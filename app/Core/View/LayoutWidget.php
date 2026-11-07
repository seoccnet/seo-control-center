<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\View;

/**
 * A piece of the signed-in layout contributed by a module, such as the
 * spending indicator in the top bar or the assistant's chat button.
 */
interface LayoutWidget
{
    /**
     * HTML for the current request, or null when the widget does not apply
     * (no workspace, missing permission, feature not set up).
     */
    public function render(Template $view): ?string;
}

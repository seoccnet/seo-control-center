<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

/**
 * A "Show me" target: a named route (project routes take the selected
 * project) and an optional CSS selector to spotlight on that page.
 */
final readonly class ShowMe
{
    public function __construct(
        public string $route,
        public ?string $selector = null,
        public string $label = 'Show me',
    ) {}
}

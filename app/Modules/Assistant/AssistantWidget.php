<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Session\Session;
use SeoControlCenter\Core\View\LayoutWidget;
use SeoControlCenter\Core\View\Template;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Workspaces\CurrentWorkspace;

/**
 * The floating "Ask AI" button and chat panel (ADR-028), on every signed-in
 * page of a workspace where the assistant is set up. The chat itself is
 * public/assets/js/assistant.js.
 */
final readonly class AssistantWidget implements LayoutWidget
{
    public function __construct(
        private AssistantService $service,
        private AssistantSettingsRepository $settings,
        private LanguageModels $models,
    ) {}

    public function render(Template $view): ?string
    {
        $user = $view->attribute(RequestAttribute::USER);
        $context = $view->attribute(RequestAttribute::WORKSPACE);
        $session = $view->attribute(RequestAttribute::SESSION);
        if (!$user instanceof User || !$context instanceof CurrentWorkspace || !$session instanceof Session || !$this->service->available($user, $context->id())) {
            return null;
        }

        $provider = $this->settings->find($context->id())->provider ?? $this->models->defaultProvider();

        return $view->partial('partials/assistant', ['csrf' => $session->csrfToken(), 'workspace' => $context->workspace()->publicId, 'provider' => $provider === 'openai' ? 'GPT' : 'Claude', 'prompts' => AssistantPrompts::all()]);
    }
}

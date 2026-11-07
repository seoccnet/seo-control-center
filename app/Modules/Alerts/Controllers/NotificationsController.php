<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Modules\Alerts\AlertService;
use SeoControlCenter\Modules\Alerts\DigestSummarizer;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * The user's notification settings (alerts, weekly digest, muted projects)
 * and the recent alerts of the current workspace.
 */
final readonly class NotificationsController
{
    public const string PATH = '/account/notifications';

    public function __construct(
        private AlertService $alerts,
        private \SeoControlCenter\Modules\Alerts\WebhookService $webhooks,
        private DigestSummarizer $summarizer,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $workspace = WebContext::workspace($request)->workspace();
        $page = $this->alerts->page(WebContext::user($request), $workspace);

        return $this->responses->html($this->views->render('account/notifications', [
            ...$page,
            ...$this->webhooks->page(WebContext::user($request), $page['projects']),
            'workspaceName' => $workspace->name,
            'aiAvailable' => $this->summarizer->available($workspace->id),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $this->alerts->save(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::body($request));
        WebContext::session($request)->flash('status', 'Your notification settings were saved.');

        return $this->responses->redirect(self::PATH);
    }

    public function saveThresholds(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->alerts->saveThresholds(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'), WebContext::body($request), \SeoControlCenter\Core\Support\ClientInfo::fromRequest($request));
        } catch (\SeoControlCenter\Domain\Shared\ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));
            return $this->responses->redirect(self::PATH . '#project-alert-thresholds');
        }
        WebContext::session($request)->flash('status', 'Project alert thresholds were saved. Existing alerts are kept; the next hourly scan uses these settings.');
        return $this->responses->redirect(self::PATH . '#project-alert-thresholds');
    }

    public function saveWebhook(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->webhooks->save(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'), WebContext::body($request), \SeoControlCenter\Core\Support\ClientInfo::fromRequest($request));
        } catch (\SeoControlCenter\Domain\Shared\ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));
            return $this->responses->redirect(self::PATH . '#alert-delivery');
        }
        WebContext::session($request)->flash('status', 'Alert delivery settings saved. They apply to future alerts.');
        return $this->responses->redirect(self::PATH . '#alert-delivery');
    }
}

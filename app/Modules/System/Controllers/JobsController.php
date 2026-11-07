<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Queue\QueueMonitor;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * System → Jobs: queue counts and failed jobs with retry and dismiss
 * (system.health.view to look, system.update to change).
 */
final readonly class JobsController
{
    public function __construct(
        private QueueMonitor $monitor,
        private Authorizer $authorizer,
        private AuditLogger $audit,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $this->authorizer->authorize($user->subject(), Permission::SystemHealthView);

        return $this->responses->html($this->views->render('system/jobs', [
            'stats' => $this->monitor->stats(),
            'failed' => $this->monitor->failed(100),
            'failedTotal' => $this->monitor->failedCount(),
            'canManage' => $this->authorizer->can($user->subject(), Permission::SystemUpdate),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function retry(ServerRequestInterface $request): ResponseInterface
    {
        $job = WebContext::routeParam($request, 'job');
        $user = $this->manage($request);
        $ok = $this->monitor->retry($job);
        $this->record($request, $user, 'system.job_retried', $job, $ok);
        WebContext::session($request)->flash($ok ? 'status' : 'error', $ok ? 'The job was queued again and runs with the next cron run.' : 'That job is no longer failed.');

        return $this->responses->redirect('/system/jobs');
    }

    public function retryAll(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->manage($request);
        $count = $this->monitor->retryAll();
        $this->record($request, $user, 'system.job_retried', 'all', true, ['count' => $count]);
        WebContext::session($request)->flash('status', sprintf('%d failed job(s) queued again.', $count));

        return $this->responses->redirect('/system/jobs');
    }

    public function dismiss(ServerRequestInterface $request): ResponseInterface
    {
        $job = WebContext::routeParam($request, 'job');
        $user = $this->manage($request);
        $ok = $this->monitor->dismiss($job);
        $this->record($request, $user, 'system.job_dismissed', $job, $ok);
        WebContext::session($request)->flash($ok ? 'status' : 'error', $ok ? 'The failed job was removed.' : 'That job is no longer failed.');

        return $this->responses->redirect('/system/jobs');
    }

    private function manage(ServerRequestInterface $request): User
    {
        $user = WebContext::user($request);
        $this->authorizer->authorize($user->subject(), Permission::SystemUpdate);

        return $user;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(ServerRequestInterface $request, User $user, string $action, string $job, bool $success, array $metadata = []): void
    {
        $this->audit->record(new AuditEvent($action, ActorType::User, actorUserId: $user->id, actorLabel: $user->email, targetType: 'job', targetId: $job, success: $success, metadata: $metadata), ClientInfo::fromRequest($request));
    }
}

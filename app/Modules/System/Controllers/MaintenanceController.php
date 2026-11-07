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
use SeoControlCenter\Core\Backup\DatabaseBackup;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Runtime;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\System\Support\SupportBundle;

/**
 * Backups (system.update) and the support bundle (system.health.view) for
 * installation administrators. Restoring stays console-only.
 */
final readonly class MaintenanceController
{
    public function __construct(
        private DatabaseBackup $backups,
        private SupportBundle $supportBundle,
        private Authorizer $authorizer,
        private AuditLogger $audit,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function backups(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizer->authorize(WebContext::user($request)->subject(), Permission::SystemUpdate);

        return $this->responses->html($this->views->render('system/backups', [
            'backups' => $this->backups->list(),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function createBackup(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $this->authorizer->authorize($user->subject(), Permission::SystemUpdate);
        Runtime::allowLongRun();
        $started = microtime(true);
        $backup = $this->backups->create();
        $this->audit->record(new AuditEvent('system.backup_created', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, targetType: 'backup', targetId: $backup->name, metadata: [
            'bytes' => $backup->size,
            'seconds' => round(microtime(true) - $started, 1),
        ]), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', sprintf('Backup %s created.', $backup->name));

        return $this->responses->redirect('/system/backups');
    }

    public function downloadBackup(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $this->authorizer->authorize($user->subject(), Permission::SystemUpdate);
        $backup = $this->backups->find(WebContext::routeParam($request, 'name')) ?? throw new NotFoundHttpException();
        $this->audit->record(new AuditEvent('system.backup_downloaded', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, targetType: 'backup', targetId: $backup->name), ClientInfo::fromRequest($request));

        return $this->responses->download($backup->path, $backup->name, 'application/gzip');
    }

    public function supportBundle(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $this->authorizer->authorize($user->subject(), Permission::SystemHealthView);
        $bundle = $this->supportBundle->build();
        $this->audit->record(new AuditEvent('system.support_bundle_downloaded', ActorType::User, actorUserId: $user->id, actorLabel: $user->email), ClientInfo::fromRequest($request));
        // The temporary file is removed at the end of the request, after the response was sent.
        register_shutdown_function(static fn(): bool => @unlink($bundle['path']));

        return $this->responses->download($bundle['path'], $bundle['name'], 'application/zip');
    }
}

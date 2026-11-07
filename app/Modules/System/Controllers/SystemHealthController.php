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
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Modules\Shared\Http\WebContext;
use SeoControlCenter\Modules\System\Health\SystemHealth;

/**
 * System Health screen for installation administrators.
 */
final readonly class SystemHealthController
{
    public function __construct(
        private SystemHealth $health,
        private Migrator $migrator,
        private AuditLogger $audit,
        private Authorizer $authorizer,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->authorizer->authorize(WebContext::user($request)->subject(), Permission::SystemHealthView);

        return $this->responses->html($this->views->render('system/health', [
            'checks' => $this->health->run(),
            'pendingMigrations' => array_map(static fn($f): string => $f->name, $this->migrator->pending()),
            'canUpdate' => $this->authorizer->can(WebContext::user($request)->subject(), Permission::SystemUpdate),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Applies pending database migrations after new files were uploaded
     * (hosts without SSH; the signed updater will do this automatically later).
     */
    public function applyMigrations(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $this->authorizer->authorize($user->subject(), Permission::SystemUpdate);

        $applied = $this->migrator->migrate();
        $this->audit->record(new AuditEvent('system.migrations_applied', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, metadata: [
            'migrations' => $applied,
        ]), ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', $applied === [] ? 'The database was already up to date.' : sprintf('Applied %d database update(s).', count($applied)));

        return $this->responses->redirect('/system/health');
    }
}

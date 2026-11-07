<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Admin\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Modules\Admin\Persistence\AuditLogReader;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class AuditLogController
{
    public function __construct(
        private AuditLogReader $query,
        private Authorizer $authorizer,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $workspace = WebContext::workspace($request)->workspace();
        $this->authorizer->authorize($user->subject(), Permission::AuditView, $workspace->id);

        $before = $request->getQueryParams()['before'] ?? null;
        $before = is_string($before) && ctype_digit($before) ? (int) $before : null;
        $rows = $this->query->forWorkspace($workspace->id, $before);

        return $this->responses->html($this->views->render('audit/index', [
            'rows' => $rows,
            'nextBefore' => count($rows) === AuditLogReader::PAGE_SIZE ? (int) end($rows)['id'] : null,
        ], $request));
    }
}

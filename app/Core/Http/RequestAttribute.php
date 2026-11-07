<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http;

/**
 * Names of PSR-7 request attributes set by core middleware.
 */
final class RequestAttribute
{
    public const string REQUEST_ID = 'scc.request_id';
    public const string CSP_NONCE = 'scc.csp_nonce';
    public const string ROUTE = 'scc.route';
    public const string ROUTE_PARAMS = 'scc.route_params';
    public const string SESSION = 'scc.session';
    public const string USER = 'scc.user';
    public const string WORKSPACE = 'scc.workspace';
    public const string WORKSPACES = 'scc.workspaces';

    private function __construct() {}
}

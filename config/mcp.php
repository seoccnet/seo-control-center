<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    'oauth' => [
        // Hosts AI apps may send the user back to after signing in (OAuth redirect URIs, https only).
        // ChatGPT: https://chatgpt.com/connector_platform_oauth_redirect; Claude: https://claude.ai/api/mcp/auth_callback.
        'redirect_hosts' => ['chatgpt.com', 'claude.ai', 'claude.com'],
        // Lifetime of the token an AI app receives; it renews it with the refresh token.
        'access_token_days' => $env->int('MCP_OAUTH_ACCESS_TOKEN_DAYS', 30),
        'refresh_token_days' => $env->int('MCP_OAUTH_REFRESH_TOKEN_DAYS', 180),
    ],
];

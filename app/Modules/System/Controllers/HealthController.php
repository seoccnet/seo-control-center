<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;

/**
 * Public liveness probe for uptime monitors. Deliberately reveals nothing
 * about versions, configuration or dependencies.
 */
final readonly class HealthController
{
    public function __construct(private ResponseFactory $responses) {}

    public function live(ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->json(['status' => 'ok'])->withHeader('Cache-Control', 'no-store');
    }
}

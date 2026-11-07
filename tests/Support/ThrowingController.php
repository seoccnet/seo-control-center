<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class ThrowingController
{
    public const string SECRET_MESSAGE = 'db password=hunter2 at 10.0.0.5';

    public function boom(ServerRequestInterface $request): ResponseInterface
    {
        throw new RuntimeException(self::SECRET_MESSAGE);
    }
}

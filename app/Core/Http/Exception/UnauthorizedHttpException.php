<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Exception;

final class UnauthorizedHttpException extends HttpException
{
    public function __construct(string $publicMessage = 'Please sign in to continue.')
    {
        parent::__construct(401, $publicMessage);
    }
}

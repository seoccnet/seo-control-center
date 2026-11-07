<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Exception;

final class NotFoundHttpException extends HttpException
{
    public function __construct(string $publicMessage = 'The page you requested was not found.')
    {
        parent::__construct(404, $publicMessage);
    }
}

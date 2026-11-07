<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Exception;

final class MethodNotAllowedHttpException extends HttpException
{
    /**
     * @param list<string> $allowedMethods
     */
    public function __construct(private readonly array $allowedMethods)
    {
        parent::__construct(405, 'This action is not allowed for this address.', ['Allow' => implode(', ', $allowedMethods)]);
    }

    /**
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        return $this->allowedMethods;
    }
}

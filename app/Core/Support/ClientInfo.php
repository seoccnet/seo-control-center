<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Support;

use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\RequestAttribute;

/**
 * Client details for sessions, rate limits and audit records.
 *
 * Uses REMOTE_ADDR only. Forwarded headers are not trusted until a
 * trusted-proxy setting exists, because they are trivially spoofable.
 */
final readonly class ClientInfo
{
    public function __construct(
        public ?string $ip,
        public ?string $userAgent,
        public ?string $requestId,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;

        $ua = $request->getHeaderLine('User-Agent');
        $ua = $ua !== '' ? mb_substr($ua, 0, 255) : null;

        $requestId = $request->getAttribute(RequestAttribute::REQUEST_ID);

        return new self($ip, $ua, is_string($requestId) ? $requestId : null);
    }

    public static function cli(): self
    {
        return new self(null, 'cli', null);
    }
}

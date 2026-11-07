<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http;

use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Convenience builder for common responses.
 */
final readonly class ResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    public function html(string $html, int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streams->createStream($html));
    }

    /**
     * @throws JsonException
     */
    public function json(mixed $data, int $status = 200): ResponseInterface
    {
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withBody($this->streams->createStream($json));
    }

    /**
     * Streams a server-side file as an attachment. $fileName must be a plain name (no quotes or paths).
     */
    public function download(string $path, string $fileName, string $contentType): ResponseInterface
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $fileName) !== 1) {
            throw new \InvalidArgumentException('Unsafe download file name.');
        }

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            ->withBody($this->streams->createStreamFromFile($path, 'rb'));
    }

    /**
     * A CSV attachment built with Csv::encode(). $fileName must be a plain name.
     *
     * @param list<list<string|int|float|bool|null>> $rows the first row is the header
     */
    public function csv(array $rows, string $fileName): ResponseInterface
    {
        if (preg_match('/^[A-Za-z0-9._-]+\.csv$/D', $fileName) !== 1) {
            throw new \InvalidArgumentException('Unsafe CSV file name.');
        }

        return $this->responses->createResponse(200)
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withBody($this->streams->createStream(Csv::encode($rows)));
    }

    public function text(string $text, int $status = 200): ResponseInterface
    {
        return $this->responses->createResponse($status)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withBody($this->streams->createStream($text));
    }

    /**
     * Redirects to a local path. Absolute URLs are rejected to prevent open redirects;
     * use redirectExternal() deliberately when needed.
     */
    public function redirect(string $path, int $status = 303): ResponseInterface
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            throw new \InvalidArgumentException('redirect() only accepts local paths.');
        }

        return $this->responses->createResponse($status)->withHeader('Location', $path);
    }

    public function empty(int $status = 204): ResponseInterface
    {
        return $this->responses->createResponse($status);
    }
}

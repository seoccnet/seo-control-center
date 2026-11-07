<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Errors;

use ErrorException;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Support\Runtime;
use Throwable;

/**
 * Process-level error handling.
 *
 * - PHP warnings/notices become ErrorException (respecting error_reporting),
 *   so they are handled like any other failure instead of leaking output.
 * - Uncaught exceptions and fatal errors outside the HTTP middleware are
 *   logged and answered with a minimal page that reveals nothing.
 */
final class PhpErrorHandler
{
    private const array FATAL = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    private bool $registered = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $isCli,
    ) {}

    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        Runtime::iniSet('display_errors', '0');
        Runtime::iniSet('log_errors', '1');
        error_reporting(E_ALL);

        set_error_handler($this->handleError(...));
        set_exception_handler($this->handleException(...));
        register_shutdown_function($this->handleShutdown(...));
    }

    public function handleError(int $severity, string $message, string $file = '', int $line = 0): bool
    {
        if ((error_reporting() & $severity) === 0) {
            // Suppressed with @ or excluded by configuration.
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    public function handleException(Throwable $e): void
    {
        $this->logger->critical('Uncaught exception: {class}', ['class' => $e::class, 'exception' => $e]);
        $this->emitFailure();
    }

    public function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], self::FATAL, true)) {
            return;
        }

        $this->logger->critical('Fatal error: {message}', [
            'message' => $error['message'],
            'file' => $error['file'] . ':' . $error['line'],
        ]);
        $this->emitFailure();
    }

    private function emitFailure(): void
    {
        if ($this->isCli) {
            fwrite(STDERR, "A fatal error occurred. See storage/logs for details.\n");

            return;
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo '<!doctype html><meta charset="utf-8"><title>Error</title>'
            . '<p>Something went wrong on our side. Please try again later.</p>';
    }
}

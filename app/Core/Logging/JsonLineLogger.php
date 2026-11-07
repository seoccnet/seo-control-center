<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Logging;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use SeoControlCenter\Core\Support\Clock;
use Stringable;
use Throwable;

/**
 * Structured PSR-3 logger writing one JSON object per line (ADR-016).
 *
 * All messages and context pass through the Redactor before being written.
 * Logging failures never break the application.
 */
final class JsonLineLogger extends AbstractLogger
{
    private const array LEVELS = [
        LogLevel::DEBUG => 100,
        LogLevel::INFO => 200,
        LogLevel::NOTICE => 250,
        LogLevel::WARNING => 300,
        LogLevel::ERROR => 400,
        LogLevel::CRITICAL => 500,
        LogLevel::ALERT => 550,
        LogLevel::EMERGENCY => 600,
    ];

    private readonly int $minimumLevel;

    public function __construct(
        private readonly string $directory,
        private readonly Redactor $redactor,
        private readonly LogContext $logContext,
        private readonly Clock $clock,
        string $minimumLevel = LogLevel::DEBUG,
        private readonly string $channel = 'app',
    ) {
        if (!isset(self::LEVELS[$minimumLevel])) {
            throw new InvalidArgumentException(sprintf('Unknown log level "%s".', $minimumLevel));
        }
        $this->minimumLevel = self::LEVELS[$minimumLevel];
    }

    /**
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !isset(self::LEVELS[$level])) {
            throw new InvalidArgumentException('Invalid log level.');
        }
        if (self::LEVELS[$level] < $this->minimumLevel) {
            return;
        }

        $record = $this->buildRecord($level, (string) $message, $context);
        $this->write($record);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<string, mixed>
     */
    public function buildRecord(string $level, string $message, array $context): array
    {
        $exception = $context['exception'] ?? null;
        unset($context['exception']);

        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));

        $record = [
            'ts' => $now->format(DateTimeImmutable::RFC3339_EXTENDED),
            'level' => $level,
            'channel' => $this->channel,
            'message' => $this->redactor->redactString($this->interpolate($message, $context)),
        ];

        $ids = $this->logContext->all();
        if ($ids !== []) {
            $record['ids'] = $ids;
        }
        if ($context !== []) {
            $record['context'] = $this->redactor->redactArray($this->normalize($context));
        }
        if ($exception instanceof Throwable) {
            $record['exception'] = $this->formatException($exception);
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function write(array $record): void
    {
        try {
            $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if ($json === false) {
                return;
            }
            if (!is_dir($this->directory)) {
                @mkdir($this->directory, 0o750, true);
            }
            $file = sprintf('%s/%s-%s.log', rtrim($this->directory, '/\\'), $this->channel, $this->clock->now()->format('Y-m-d'));
            @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // Logging must never take the application down.
        }
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private function interpolate(string $message, array $context): string
    {
        if (!str_contains($message, '{')) {
            return $message;
        }
        $replacements = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /**
     * @param array<array-key, mixed> $context
     * @return array<array-key, mixed>
     */
    private function normalize(array $context, int $depth = 0): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            $result[$key] = match (true) {
                $value === null, is_scalar($value) => $value,
                is_array($value) => $depth < 6 ? $this->normalize($value, $depth + 1) : '[array]',
                $value instanceof \DateTimeInterface => $value->format(DATE_RFC3339),
                $value instanceof Throwable => $this->formatException($value),
                $value instanceof Stringable => (string) $value,
                is_object($value) => '[object ' . $value::class . ']',
                default => '[' . get_debug_type($value) . ']',
            };
        }

        return $result;
    }

    /**
     * Formats an exception without call arguments (which may contain secrets).
     *
     * @return array<string, mixed>
     */
    private function formatException(Throwable $e, int $depth = 0): array
    {
        $frames = [];
        foreach (array_slice($e->getTrace(), 0, 25) as $frame) {
            $frames[] = sprintf(
                '%s%s%s() %s:%d',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'],
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? 0,
            );
        }

        $data = [
            'class' => $e::class,
            'message' => $this->redactor->redactString($e->getMessage()),
            'code' => $e->getCode(),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => $frames,
        ];

        $previous = $e->getPrevious();
        if ($previous !== null && $depth < 3) {
            $data['previous'] = $this->formatException($previous, $depth + 1);
        }

        return $data;
    }
}

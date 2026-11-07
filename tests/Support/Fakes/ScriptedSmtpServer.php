<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Mail\MailException;
use SeoControlCenter\Core\Mail\SmtpConnection;

/**
 * Plays back server replies and records what the client writes.
 */
final class ScriptedSmtpServer implements SmtpConnection
{
    /** @var list<string> */
    public array $written = [];

    /** @var array{0: string, 1: int, 2: bool}|null */
    public ?array $opened = null;

    public bool $tlsEnabled = false;

    public bool $closed = false;

    /**
     * @param list<string> $replies
     */
    public function __construct(private array $replies) {}

    public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void
    {
        $this->opened = [$host, $port, $implicitTls];
    }

    public function readLine(): string
    {
        if ($this->replies === []) {
            throw new MailException('The mail server closed the connection.');
        }

        return array_shift($this->replies);
    }

    public function write(string $data): void
    {
        $this->written[] = $data;
    }

    public function enableTls(): void
    {
        $this->tlsEnabled = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /**
     * @return list<string> written commands without line endings
     */
    public function commands(): array
    {
        return array_map(static fn(string $w): string => rtrim($w, "\r\n"), $this->written);
    }
}

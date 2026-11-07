<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

/**
 * The network side of the SMTP client, separate so it can be replaced in tests.
 */
interface SmtpConnection
{
    /**
     * @throws MailException when the server cannot be reached
     */
    public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void;

    /**
     * One response line without its line ending.
     *
     * @throws MailException on timeout or a closed connection
     */
    public function readLine(): string;

    /**
     * @throws MailException
     */
    public function write(string $data): void;

    /**
     * Upgrades the connection after STARTTLS (certificate verified).
     *
     * @throws MailException
     */
    public function enableTls(): void;

    public function close(): void;
}

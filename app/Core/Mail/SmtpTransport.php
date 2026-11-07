<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

/**
 * A minimal SMTP client (ADR-027): plain, STARTTLS or implicit TLS; AUTH
 * PLAIN or LOGIN; one recipient per message. Error messages quote the
 * server's reply but never the password.
 */
final readonly class SmtpTransport
{
    public const int TIMEOUT_SECONDS = 10;

    public function __construct(
        private SmtpConnection $connection,
        private MessageFormatter $formatter,
        private string $heloName = 'localhost',
    ) {}

    /**
     * @throws MailException
     */
    public function send(MailSettings $settings, Message $message): void
    {
        $host = (string) $settings->host;
        $from = (string) $settings->fromAddress;
        $formatted = $this->formatter->format($message, $from, $settings->fromName);

        $this->connection->open($host, $settings->port(), $settings->encryption === 'ssl', self::TIMEOUT_SECONDS);
        try {
            $this->expect([220], 'connect');
            $capabilities = $this->ehlo();
            if ($settings->encryption === 'tls') {
                if (!isset($capabilities['STARTTLS'])) {
                    throw new MailException('The mail server does not offer STARTTLS. Choose another encryption setting or port.');
                }
                $this->command('STARTTLS', [220]);
                $this->connection->enableTls();
                $capabilities = $this->ehlo();
            }
            if ($settings->username !== null && $settings->username !== '') {
                $this->authenticate($settings->username, (string) $settings->password, $capabilities['AUTH'] ?? '');
            }
            $this->command('MAIL FROM:<' . $from . '>', [250]);
            $this->command('RCPT TO:<' . $message->to . '>', [250, 251]);
            $this->command('DATA', [354]);
            $data = MessageFormatter::headerBlock($formatted['headers']) . "\r\n\r\n" . $formatted['body'];
            // Dot-stuffing (RFC 5321, 4.5.2), then the end-of-data marker.
            $data = (string) preg_replace('/^\./m', '..', $data);
            $this->connection->write($data . "\r\n.\r\n");
            $this->expect([250], 'DATA');
            $this->connection->write("QUIT\r\n");
        } finally {
            $this->connection->close();
        }
    }

    /**
     * @return array<string, string> capability keyword => parameters
     */
    private function ehlo(): array
    {
        $lines = $this->command('EHLO ' . $this->heloName, [250]);
        $capabilities = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', strtoupper(trim(substr($line, 4))), 2);
            $capabilities[$parts[0]] = $parts[1] ?? '';
        }

        return $capabilities;
    }

    private function authenticate(string $username, #[\SensitiveParameter] string $password, string $mechanisms): void
    {
        $offered = explode(' ', $mechanisms);
        if (in_array('PLAIN', $offered, true) || !in_array('LOGIN', $offered, true)) {
            $this->connection->write('AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password) . "\r\n");
            $this->expect([235], 'AUTH', 'The mail server rejected the user name or password.');

            return;
        }
        $this->command('AUTH LOGIN', [334]);
        $this->command(base64_encode($username), [334]);
        $this->connection->write(base64_encode($password) . "\r\n");
        $this->expect([235], 'AUTH', 'The mail server rejected the user name or password.');
    }

    /**
     * @param list<int> $codes
     * @return list<string>
     */
    private function command(string $command, array $codes): array
    {
        $this->connection->write($command . "\r\n");

        return $this->expect($codes, strtok($command, ' ') ?: $command);
    }

    /**
     * Reads a (multi-line) reply and checks its code.
     *
     * @param list<int> $codes
     * @return list<string>
     */
    private function expect(array $codes, string $step, ?string $failure = null): array
    {
        $lines = [];
        do {
            $line = $this->connection->readLine();
            $lines[] = $line;
        } while (strlen($line) >= 4 && $line[3] === '-');

        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new MailException($failure ?? sprintf('The mail server refused %s: %s', $step, mb_strimwidth($line, 0, 200, '…')));
        }

        return $lines;
    }
}

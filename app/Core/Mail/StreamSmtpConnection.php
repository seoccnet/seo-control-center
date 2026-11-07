<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

/**
 * SMTP over PHP streams. TLS certificates are verified against the host name.
 */
final class StreamSmtpConnection implements SmtpConnection
{
    /** @var resource|null */
    private $stream = null;

    private string $host = '';

    public function open(string $host, int $port, bool $implicitTls, int $timeoutSeconds): void
    {
        $this->host = $host;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
        ]]);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port,
            $errno,
            $errstr,
            $timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($stream === false) {
            throw new MailException(sprintf('Could not connect to %s:%d (%s).', $host, $port, $errstr !== '' ? $errstr : 'error ' . $errno));
        }
        stream_set_timeout($stream, $timeoutSeconds + 5);
        $this->stream = $stream;
    }

    public function readLine(): string
    {
        $line = $this->stream !== null ? fgets($this->stream, 1024) : false;
        if ($line === false) {
            $timedOut = $this->stream !== null && stream_get_meta_data($this->stream)['timed_out'];
            throw new MailException($timedOut ? 'The mail server did not answer in time.' : 'The mail server closed the connection.');
        }

        return rtrim($line, "\r\n");
    }

    public function write(string $data): void
    {
        if ($this->stream === null) {
            throw new MailException('Not connected to the mail server.');
        }
        $written = 0;
        $length = strlen($data);
        while ($written < $length) {
            $chunk = fwrite($this->stream, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                throw new MailException('Could not write to the mail server.');
            }
            $written += $chunk;
        }
    }

    public function enableTls(): void
    {
        if ($this->stream === null) {
            throw new MailException('Not connected to the mail server.');
        }
        stream_context_set_option($this->stream, 'ssl', 'peer_name', $this->host);
        $ok = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
        if ($ok !== true) {
            throw new MailException('Could not start an encrypted connection (STARTTLS). Check the host name and the encryption setting.');
        }
    }

    public function close(): void
    {
        if ($this->stream !== null) {
            fclose($this->stream);
            $this->stream = null;
        }
    }
}

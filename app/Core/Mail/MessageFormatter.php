<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

use SeoControlCenter\Core\Support\Clock;

/**
 * Builds the RFC 5322 headers and the quoted-printable UTF-8 body of a
 * message: plain text, or multipart/alternative (text, then HTML) when the
 * message has an HTML version, wrapped in multipart/mixed with base64
 * attachments when it has any. Line endings are CRLF.
 */
final readonly class MessageFormatter
{
    public function __construct(private Clock $clock) {}

    /**
     * @return array{headers: array<string, string>, body: string}
     */
    public function format(Message $message, string $fromAddress, ?string $fromName): array
    {
        if (filter_var($fromAddress, FILTER_VALIDATE_EMAIL) === false || preg_match('/[\r\n]/', $fromName ?? '') === 1) {
            throw new MailException('The sender address is not valid. Check System → E-mail.');
        }
        $domain = substr((string) strrchr($fromAddress, '@'), 1) ?: 'localhost';
        $headers = [
            'Date' => $this->clock->now()->format(DATE_RFC2822),
            'From' => self::address($fromAddress, $fromName),
            'To' => self::address($message->to, $message->toName),
            'Subject' => self::encodeWord($message->subject),
            'Message-ID' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
        ];
        $text = quoted_printable_encode(self::crlf($message->body));
        $content = ['type' => 'text/plain; charset=UTF-8', 'encoding' => 'quoted-printable', 'body' => $text];
        if ($message->html !== null) {
            $boundary = 'scc-' . bin2hex(random_bytes(12));
            $part = static fn(string $type, string $encoded): string => '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $type . "; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . $encoded . "\r\n";
            $content = [
                'type' => 'multipart/alternative; boundary="' . $boundary . '"',
                'encoding' => null,
                'body' => "This is a multi-part message in MIME format.\r\n\r\n"
                    . $part('text/plain', $text)
                    . $part('text/html', quoted_printable_encode(self::crlf($message->html)))
                    . '--' . $boundary . "--\r\n",
            ];
        }
        if ($message->attachments === []) {
            $headers['Content-Type'] = $content['type'];
            if ($content['encoding'] === null) {
                unset($headers['Content-Transfer-Encoding']);
            }

            return ['headers' => $headers, 'body' => $content['body']];
        }

        $mixed = 'scc-' . bin2hex(random_bytes(12));
        $headers['Content-Type'] = 'multipart/mixed; boundary="' . $mixed . '"';
        unset($headers['Content-Transfer-Encoding']);
        $body = "This is a multi-part message in MIME format.\r\n\r\n--" . $mixed . "\r\nContent-Type: " . $content['type'] . "\r\n"
            . ($content['encoding'] !== null ? 'Content-Transfer-Encoding: ' . $content['encoding'] . "\r\n" : '') . "\r\n" . $content['body'] . "\r\n";
        foreach ($message->attachments as $attachment) {
            $body .= '--' . $mixed . "\r\nContent-Type: " . $attachment->contentType . '; name="' . $attachment->fileName . "\"\r\n"
                . 'Content-Disposition: attachment; filename="' . $attachment->fileName . "\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . rtrim(chunk_split(base64_encode($attachment->content), 76, "\r\n")) . "\r\n";
        }

        return ['headers' => $headers, 'body' => $body . '--' . $mixed . "--\r\n"];
    }

    private static function crlf(string $text): string
    {
        return (string) preg_replace("/\r\n|\r|\n/", "\r\n", $text);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function headerBlock(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines);
    }

    /**
     * "Name <address>"; non-ASCII names are RFC 2047 encoded, names with
     * special characters are quoted.
     */
    private static function address(string $email, ?string $name): string
    {
        if ($name === null || $name === '') {
            return $email;
        }
        if (self::isAscii($name)) {
            $specials = '()<>[]:;@\\,."';
            if (strpbrk($name, $specials) !== false) {
                $name = '"' . addcslashes($name, '"\\') . '"';
            }

            return $name . ' <' . $email . '>';
        }

        return self::encodeWord($name) . ' <' . $email . '>';
    }

    /**
     * RFC 2047 encoding for header text with non-ASCII characters.
     */
    private static function encodeWord(string $text): string
    {
        return self::isAscii($text) ? $text : '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    private static function isAscii(string $text): bool
    {
        return preg_match('/^[\x20-\x7e]*$/D', $text) === 1;
    }
}

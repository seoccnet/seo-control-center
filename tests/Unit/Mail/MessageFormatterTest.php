<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Mail;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Mail\Attachment;
use SeoControlCenter\Core\Mail\Message;
use SeoControlCenter\Core\Mail\MessageFormatter;
use SeoControlCenter\Core\Support\FrozenClock;

final class MessageFormatterTest extends TestCase
{
    public function testPlainTextStaysASinglePart(): void
    {
        $formatted = $this->formatter()->format(new Message('a@example.test', 'Hi', "Line 1\nLine 2"), 'scc@example.test', 'SCC');

        self::assertSame('text/plain; charset=UTF-8', $formatted['headers']['Content-Type']);
        self::assertSame("Line 1\r\nLine 2", $formatted['body']);
    }

    public function testHtmlVersionMakesAMultipartAlternativeWithTextFirst(): void
    {
        $formatted = $this->formatter()->format(new Message('a@example.test', 'Report', "Clicks: 12\n", null, '<p style="color:#141a22">Clicks: <strong>12</strong> · ▲</p>'), 'scc@example.test', null);

        self::assertSame(1, preg_match('/^multipart\/alternative; boundary="(scc-[0-9a-f]{24})"$/', $formatted['headers']['Content-Type'], $m));
        self::assertArrayNotHasKey('Content-Transfer-Encoding', $formatted['headers']);
        $parts = explode('--' . $m[1], $formatted['body']);
        self::assertCount(4, $parts, 'Preamble, text, HTML, closing marker.');
        self::assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nClicks: 12", $parts[1]);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $parts[2]);
        self::assertSame('<p style="color:#141a22">Clicks: <strong>12</strong> · ▲</p>', quoted_printable_decode(trim(explode("\r\n\r\n", $parts[2], 2)[1])));
        self::assertSame("--\r\n", $parts[3]);
        foreach (explode("\r\n", $formatted['body']) as $line) {
            self::assertLessThanOrEqual(998, strlen($line), 'SMTP line limit.');
        }
    }

    public function testAttachmentsWrapTheBodyInMultipartMixed(): void
    {
        $pdf = "%PDF-1.7\n" . random_bytes(300);
        $formatted = $this->formatter()->format(new Message('a@example.test', 'Report', "Clicks: 12\n", null, '<p>Clicks</p>', [new Attachment('seo-report-acme-2026-09-27.pdf', 'application/pdf', $pdf)]), 'scc@example.test', null);

        self::assertSame(1, preg_match('/^multipart\/mixed; boundary="(scc-[0-9a-f]{24})"$/', $formatted['headers']['Content-Type'], $m));
        self::assertArrayNotHasKey('Content-Transfer-Encoding', $formatted['headers']);
        $parts = explode('--' . $m[1], $formatted['body']);
        self::assertCount(4, $parts, 'Preamble, alternative body, attachment, closing marker.');
        self::assertMatchesRegularExpression('/^\r\nContent-Type: multipart\/alternative; boundary="scc-[0-9a-f]{24}"\r\n\r\n/', $parts[1]);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $parts[1]);
        [$head, $encoded] = explode("\r\n\r\n", $parts[2], 2);
        self::assertStringContainsString('Content-Type: application/pdf; name="seo-report-acme-2026-09-27.pdf"', $head);
        self::assertStringContainsString('Content-Disposition: attachment; filename="seo-report-acme-2026-09-27.pdf"', $head);
        self::assertStringContainsString('Content-Transfer-Encoding: base64', $head);
        self::assertSame($pdf, base64_decode(str_replace("\r\n", '', trim($encoded)), true));
        foreach (explode("\r\n", $formatted['body']) as $line) {
            self::assertLessThanOrEqual(998, strlen($line), 'SMTP line limit.');
        }

        $plain = $this->formatter()->format(new Message('a@example.test', 'Report', 'Text', null, null, [new Attachment('a.pdf', 'application/pdf', 'x')]), 'scc@example.test', null);
        self::assertStringContainsString("Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nText", $plain['body']);
    }

    public function testAttachmentNamesCannotBreakHeaders(): void
    {
        foreach (['report".pdf', "report\r\n.pdf", '../report.pdf', ''] as $name) {
            try {
                new Attachment($name, 'application/pdf', 'x');
                self::fail('Accepted ' . json_encode($name));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('attachment file name', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new Attachment('a.pdf', "application/pdf\r\nX: y", 'x');
    }

    private function formatter(): MessageFormatter
    {
        return new MessageFormatter(new FrozenClock(new \DateTimeImmutable('2026-10-02 12:00:00 UTC')));
    }
}

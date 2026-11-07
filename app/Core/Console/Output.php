<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console;

/**
 * Console output with optional ANSI colours.
 */
final class Output
{
    /** @var resource */
    private $stdout;

    /** @var resource */
    private $stderr;

    private bool $colors;

    /**
     * @param resource|null $stdout
     * @param resource|null $stderr
     */
    public function __construct($stdout = null, $stderr = null, ?bool $colors = null)
    {
        $this->stdout = $stdout ?? STDOUT;
        $this->stderr = $stderr ?? STDERR;
        $this->colors = $colors ?? (function_exists('stream_isatty') && @stream_isatty($this->stdout));
    }

    public function line(string $text = ''): void
    {
        fwrite($this->stdout, $text . PHP_EOL);
    }

    public function info(string $text): void
    {
        $this->line($this->color($text, '32'));
    }

    public function warning(string $text): void
    {
        $this->line($this->color($text, '33'));
    }

    public function error(string $text): void
    {
        fwrite($this->stderr, $this->color($text, '31') . PHP_EOL);
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function table(array $headers, array $rows): void
    {
        $widths = array_map('mb_strlen', $headers);
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }
        $format = static function (array $cells) use ($widths): string {
            $out = [];
            foreach ($widths as $i => $width) {
                $cell = (string) ($cells[$i] ?? '');
                $out[] = $cell . str_repeat(' ', $width - mb_strlen($cell));
            }

            return rtrim(implode('  ', $out));
        };
        $this->line($format($headers));
        $this->line($format(array_map(static fn(int $w): string => str_repeat('-', $w), $widths)));
        foreach ($rows as $row) {
            $this->line($format($row));
        }
    }

    private function color(string $text, string $code): string
    {
        return $this->colors ? "\033[{$code}m{$text}\033[0m" : $text;
    }
}

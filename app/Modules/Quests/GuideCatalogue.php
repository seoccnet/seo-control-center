<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests;

/**
 * The packaged user guide (resources/guide/catalog.json, built from
 * docs/guide by bin/sync-ai-guide.php), so quests can link to a chapter
 * inside SEOcc. The text is our own documentation; it is still escaped.
 */
final class GuideCatalogue
{
    /** @var array<string, array{title: string, text: string}>|null */
    private ?array $chapters = null;

    public function __construct(private readonly string $path) {}

    public function title(string $id): ?string
    {
        return $this->chapters()[$id]['title'] ?? null;
    }

    /** @return array<string, string> chapter id => title */
    public function titles(): array
    {
        return array_map(static fn(array $c): string => $c['title'], $this->chapters());
    }

    /** The chapter as safe HTML: headings, paragraphs, lists, tables as text, bold and inline code. */
    public function html(string $id): ?string
    {
        $text = $this->chapters()[$id]['text'] ?? null;
        if ($text === null) {
            return null;
        }
        // Blocks: $tags[i] is "p" (joined lines), "ul"/"ol" (one item per line), "raw" (finished element) or "break".
        $tags = [];
        $items = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $trimmed = trim($line);
            $last = count($tags) - 1;
            if ($trimmed === '' || preg_match('/^\|?\s*-{3,}/', $trimmed) === 1) {
                $tags[] = 'break';
                $items[] = [];
            } elseif (preg_match('/^(#{1,4})\s+(.*)$/', $trimmed, $m) === 1) {
                $level = min(4, strlen($m[1]) + 1);
                $tags[] = 'raw';
                $items[] = ['<h' . $level . '>' . self::inline($m[2]) . '</h' . $level . '>'];
            } elseif (preg_match('/^(?:([-*])|(\d+)\.)\s+(.*)$/', $trimmed, $m) === 1) {
                $type = $m[1] !== '' ? 'ul' : 'ol';
                if ($last >= 0 && $tags[$last] === $type) {
                    $items[$last][] = self::inline($m[3]);
                } else {
                    $tags[] = $type;
                    $items[] = [self::inline($m[3])];
                }
            } elseif (str_starts_with($trimmed, '|')) {
                $cells = array_map(trim(...), explode('|', trim($trimmed, '|')));
                $tags[] = 'raw';
                $items[] = ['<p class="guide-row">' . implode(' · ', array_map(self::inline(...), $cells)) . '</p>'];
            } elseif ($last >= 0 && $tags[$last] === 'p') {
                $items[$last][] = self::inline($trimmed);
            } else {
                $tags[] = 'p';
                $items[] = [self::inline($trimmed)];
            }
        }
        $html = [];
        foreach ($tags as $i => $tag) {
            $block = $items[$i] ?? [];
            $html[] = match ($tag) {
                'p' => '<p>' . implode(' ', $block) . '</p>',
                'ul', 'ol' => '<' . $tag . '><li>' . implode('</li><li>', $block) . '</li></' . $tag . '>',
                'raw' => implode('', $block),
                default => '',
            };
        }

        return implode("\n", array_filter($html, static fn(string $h): bool => $h !== ''));
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        // Links keep their text only; chapter links point to the in-app guide.
        $text = (string) preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            if (preg_match('/^(\d{2})-[a-z0-9-]+\.md$/', $m[2], $chapter) === 1) {
                return '<a href="/quests/guide/' . $chapter[1] . '">' . $m[1] . '</a>';
            }

            return $m[1];
        }, $text);
        $text = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);

        return (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    }

    /** @return array<string, array{title: string, text: string}> */
    private function chapters(): array
    {
        if ($this->chapters !== null) {
            return $this->chapters;
        }
        $raw = is_file($this->path) ? file_get_contents($this->path) : false;
        $catalog = $raw !== false ? json_decode($raw, true) : null;
        $chapters = [];
        foreach (is_array($catalog) && is_array($catalog['chapters'] ?? null) ? $catalog['chapters'] : [] as $id => $chapter) {
            if (is_array($chapter) && is_string($chapter['title'] ?? null) && is_string($chapter['text'] ?? null)) {
                $chapters[(string) $id] = ['title' => $chapter['title'], 'text' => $chapter['text']];
            }
        }

        return $this->chapters = $chapters;
    }
}

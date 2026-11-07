<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\View;

/**
 * The interface's icon set: 24px stroke icons drawn inline, so they follow
 * the text colour and need no extra requests or CSP exceptions.
 */
final class Icons
{
    private const array PATHS = [
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'folder' => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'plug' => '<path d="M9 2v6M15 2v6M6 8h12v4a6 6 0 0 1-12 0zM12 18v4"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
        'list' => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
        'shield' => '<path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6z"/>',
        'pulse' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5zM3 13l9 5 9-5"/>',
        'database' => '<ellipse cx="12" cy="5.5" rx="8" ry="3"/><path d="M4 5.5v13c0 1.7 3.6 3 8 3s8-1.3 8-3v-13M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'download' => '<path d="M12 3v12m0 0-4.5-4.5M12 15l4.5-4.5M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/>',
        'google' => '<path d="M20 12.2c0-.6-.1-1.2-.2-1.7H12v3.3h4.5a3.9 3.9 0 0 1-1.7 2.5v2.1h2.7c1.6-1.5 2.5-3.6 2.5-6.2z"/><path d="M12 20.5c2.3 0 4.2-.8 5.5-2.1l-2.7-2.1a5 5 0 0 1-7.5-2.7H4.5v2.2A8.5 8.5 0 0 0 12 20.5z"/><path d="M7.3 13.6a5 5 0 0 1 0-3.2V8.2H4.5a8.5 8.5 0 0 0 0 7.6z"/><path d="M12 6.8c1.3 0 2.4.4 3.3 1.3l2.5-2.4A8.5 8.5 0 0 0 4.5 8.2l2.8 2.2A5 5 0 0 1 12 6.8z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'arrow-right' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.9-3M4 4v4h4M4 13a8 8 0 0 0 14.9 3M20 20v-4h-4"/>',
        'bug' => '<rect x="7" y="8" width="10" height="12" rx="5"/><path d="M9 8V7a3 3 0 0 1 6 0v1M12 12v8M3 13h4M17 13h4M4 7l3 2M20 7l-3 2M4 19l3-2M20 19l-3-2"/>',
        'discord' => '<path d="M8.5 5.5 7 4.5C5 5 3.6 5.8 3 6.5 1.7 9.6 1.4 13 2 16.5c1.4 1.3 3 2.2 4.6 2.7L8 17"/><path d="M15.5 5.5 17 4.5c2 .5 3.4 1.3 4 2 1.3 3.1 1.6 6.5 1 10-1.4 1.3-3 2.2-4.6 2.7L16 17"/><path d="M7 16.2c3.3 1.4 6.7 1.4 10 0M7 7.4c3.3-1.3 6.7-1.3 10 0"/><circle cx="9" cy="12.5" r="1.3"/><circle cx="15" cy="12.5" r="1.3"/>',
        'chat' => '<path d="M4 5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4z"/><path d="M8.5 8.5h7M8.5 12h4.5"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'expand' => '<path d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 7.5v.01"/>',
        'send' => '<path d="M4 12 20 4l-6 16-3-7z"/><path d="m11 13 9-9"/>',
        'bolt' => '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/>',
        'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l-5-5 5-5M5 12h11"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    ];

    public static function svg(string $name): string
    {
        $paths = self::PATHS[$name] ?? throw new \InvalidArgumentException(sprintf('Unknown icon "%s".', $name));

        return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths . '</svg>';
    }
}

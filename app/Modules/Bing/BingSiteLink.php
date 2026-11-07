<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

/**
 * The Bing site that feeds a project, with its import state.
 */
final readonly class BingSiteLink
{
    public const string PENDING = 'pending';
    public const string IMPORTING = 'importing';
    public const string OK = 'ok';
    public const string ERROR = 'error';

    public function __construct(
        public int $projectId,
        public int $workspaceId,
        public string $siteUrl,
        public string $status,
        public ?string $lastSuccessAt,
        public ?string $lastError,
        public string $linkedAt,
    ) {}
}

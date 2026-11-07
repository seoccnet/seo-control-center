<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing\Jobs;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Database\LeaseLock;
use SeoControlCenter\Core\Queue\JobContext;
use SeoControlCenter\Core\Queue\JobHandler;
use SeoControlCenter\Core\Queue\PermanentJobException;
use SeoControlCenter\Modules\Bing\Import\BingImporter;

/**
 * Imports one project's Bing data. A per-project lock keeps two runs apart.
 */
final readonly class ImportBingJob implements JobHandler
{
    public const string TYPE = 'bing.import';
    public const string QUEUE = 'imports';

    public function __construct(
        private BingImporter $importer,
        private Connection $db,
    ) {}

    public function handle(array $payload, JobContext $context): void
    {
        $projectId = $payload['project_id'] ?? null;
        if (!is_int($projectId) || $projectId < 1) {
            throw new PermanentJobException('Invalid project_id.');
        }
        $lock = new LeaseLock($this->db, 'bing.import:' . $projectId, 180);
        if (!$lock->acquire()) {
            return; // Another run is importing this project right now.
        }
        try {
            $this->importer->run($projectId);
        } finally {
            $lock->release();
        }
    }
}

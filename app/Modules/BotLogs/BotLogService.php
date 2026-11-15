<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\BotLogs\LogLineParser;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Search bot log analysis (1.2.0). People upload a raw access log (plain
 * or gzip); a background job reads it in chunks, keeps only the requests
 * of known search and AI crawlers as totals per bot, path and status, and
 * deletes the file. The report shows where each bot spends its requests:
 * errors, redirects, parameter URLs, the most requested paths, and pages
 * with Search Console clicks that the bot did not request in the log.
 *
 * Controllers authorize the project before calling; uploads and deletes
 * need Edit projects. Bots are recognised by user agent only.
 */
final readonly class BotLogService
{
    public const int MAX_MB = 200;
    /** Distinct (bot, path, status) rows kept per import; further new rows count as dropped hits. */
    public const int MAX_ROWS = 100000;
    public const int KEEP = 5;
    private const int LINES_PER_CHUNK = 20000;
    private const int RESERVE_SECONDS = 15;
    private const int STALE_HOURS = 6;
    private const int UPLOADS_PER_HOUR = 10;

    public function __construct(
        private BotLogRepository $logs,
        private BotLogScheduler $scheduler,
        private SearchPerformanceReader $search,
        private Authorizer $auth,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
        private string $directory,
        private ?CrawlerRangeSource $ranges = null,
    ) {}

    /** @return list<BotLogImport> */
    public function list(Project $project): array
    {
        return $this->logs->list($project->id, self::KEEP);
    }

    public function get(Project $project, string $publicId): BotLogImport
    {
        return $this->logs->find($project->id, $publicId) ?? throw new NotFoundHttpException();
    }

    public function canEdit(User $user, Project $project): bool
    {
        return $this->auth->can($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
    }

    /** @throws HttpException 403 when the user may not upload logs to the project */
    public function authorizeUpload(User $user, Project $project): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
    }

    /** A private path for an upload to be moved to before accept() checks it. */
    public function incomingPath(): string
    {
        $this->ensureDirectory();

        return $this->directory . '/incoming-' . bin2hex(random_bytes(12));
    }

    /**
     * Takes an uploaded file (already moved to incomingPath()) and queues
     * it for reading. The file is deleted when it is refused.
     */
    public function accept(User $user, Project $project, string $path, string $originalName, ClientInfo $client): BotLogImport
    {
        try {
            $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
            $bytes = is_file($path) ? (int) filesize($path) : 0;
            if ($bytes === 0) {
                throw new HttpException(422, 'The file is empty.');
            }
            if ($bytes > self::MAX_MB * 1048576) {
                throw new HttpException(422, sprintf('Logs up to %d MB can be uploaded. Compress it with gzip or upload a shorter period.', self::MAX_MB));
            }
            if (!self::readable($path)) {
                throw new HttpException(422, 'This does not look like a text or gzip access log.');
            }
            if (!$this->rateLimiter->hit('bot-logs:project:' . $project->id, self::UPLOADS_PER_HOUR, 3600)->allowed) {
                throw new HttpException(429, 'Too many log uploads for this project. Try again later.');
            }
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        }
        $name = mb_substr(trim((string) preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', basename($originalName))), 0, 200) ?: 'access.log';
        $import = $this->logs->create($project->id, $project->workspaceId, $name, $bytes, $user->id);
        if (!@rename($path, $this->path($import))) {
            @unlink($path);
            $this->logs->finish($import->id, BotLogImport::FAILED, 'The upload could not be stored.');
            throw new \RuntimeException('The uploaded log could not be stored.');
        }
        $this->scheduler->import($import->id, $project->workspaceId, 0);
        $this->audit->record(new AuditEvent('project.bot_log_uploaded', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['import' => $import->publicId, 'bytes' => $bytes]), $client);

        return $import;
    }

    public function delete(User $user, Project $project, BotLogImport $import, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->remove($import);
        $this->logs->delete($import->id);
        $this->audit->record(new AuditEvent('project.bot_log_deleted', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['import' => $import->publicId]), $client);
    }

    /**
     * Job: reads chunks from the saved offset until the file ends or time
     * runs short, then queues a continuation.
     *
     * @param \Closure(): int $secondsRemaining
     * @param (\Closure(): bool)|null $renewLease
     */
    public function run(int $importId, \Closure $secondsRemaining, ?\Closure $renewLease = null): void
    {
        $import = $this->logs->byId($importId);
        if ($import === null || !$import->active()) {
            return;
        }
        $path = $this->path($import);
        if (!is_file($path)) {
            $this->logs->finish($import->id, BotLogImport::FAILED, 'The uploaded file is gone. Upload it again.');

            return;
        }
        $this->logs->markRunning($import->id);
        // Published crawler IP ranges (1.2.1); a crawler counts as verified only if every run could check it.
        $ranges = $this->ranges?->ranges() ?? [];
        $this->logs->restrictVerifiedBots($import->id, array_keys($ranges), $import->byteOffset === 0);
        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('The log file cannot be opened.');
        }
        try {
            if ($import->byteOffset > 0 && gzseek($handle, $import->byteOffset) !== 0) {
                throw new \RuntimeException('The log could not be resumed.');
            }
            while (true) {
                $hits = [];
                $lines = 0;
                $botLines = 0;
                $first = null;
                $last = null;
                while ($lines < self::LINES_PER_CHUNK && ($line = gzgets($handle, 16385)) !== false) {
                    ++$lines;
                    $hit = LogLineParser::parse(rtrim($line, "\r\n"));
                    if ($hit === null) {
                        continue;
                    }
                    ++$botLines;
                    $key = $hit['bot'] . '|' . $hit['status'] . '|' . $hit['path'];
                    $hits[$key] ??= ['bot' => $hit['bot'], 'path' => $hit['path'], 'status' => $hit['status'], 'hits' => 0, 'verified' => 0];
                    ++$hits[$key]['hits'];
                    // The address is checked here and then dropped; it is never stored.
                    if ($hit['ip'] !== null && isset($ranges[$hit['bot']]) && $ranges[$hit['bot']]->contains($hit['ip'])) {
                        ++$hits[$key]['verified'];
                    }
                    if ($hit['time'] !== null) {
                        $first = $first === null || $hit['time'] < $first ? $hit['time'] : $first;
                        $last = $last === null || $hit['time'] > $last ? $hit['time'] : $last;
                    }
                }
                $offset = (int) gztell($handle);
                if (!$this->logs->saveChunk($import->id, $hits, $offset, $lines, $botLines, $first, $last, self::MAX_ROWS)) {
                    return;
                }
                if (gzeof($handle) || $lines < self::LINES_PER_CHUNK) {
                    gzclose($handle);
                    $handle = null;
                    $this->logs->finish($import->id, BotLogImport::DONE);
                    $this->remove($import);
                    $this->logger->info('Bot log read', ['import_id' => $import->id]);

                    return;
                }
                if ($secondsRemaining() < self::RESERVE_SECONDS || ($renewLease !== null && !$renewLease())) {
                    $this->scheduler->import($import->id, $import->workspaceId, $offset);

                    return;
                }
            }
        } finally {
            if ($handle !== null) {
                gzclose($handle);
            }
        }
    }

    /** Job: marks an import failed after its last attempt and removes its file. */
    public function fail(int $importId, string $error): void
    {
        $import = $this->logs->byId($importId);
        if ($import === null) {
            return;
        }
        $this->logs->finish($import->id, BotLogImport::FAILED, $error);
        $this->remove($import);
    }

    /** Cleanup job: fails stalled imports, drops imports beyond KEEP per project, deletes orphaned files. */
    public function cleanup(): int
    {
        $now = $this->clock->now();
        $removed = 0;
        foreach ($this->logs->due($now->modify('-' . self::STALE_HOURS . ' hours')->format('Y-m-d H:i:s'), self::KEEP) as $import) {
            if ($import->active()) {
                $this->fail($import->id, 'Reading the log stopped making progress. Upload it again.');
            } else {
                $this->logs->delete($import->id);
            }
            $this->remove($import);
            ++$removed;
        }
        $oldest = $now->modify('-' . (self::STALE_HOURS * 2) . ' hours')->getTimestamp();
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $oldest && @unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * What one bot did in the log.
     *
     * @return array{
     *     bots: array<string, array{hits: int, paths: int, ok: int, redirects: int, client_errors: int, server_errors: int, with_query: int, verified: ?int}>,
     *     bot: string,
     *     top: list<array{path: string, status: int, hits: int}>,
     *     errors: list<array{path: string, status: int, hits: int}>,
     *     redirects: list<array{path: string, status: int, hits: int}>,
     *     parameters: list<array{path: string, status: int, hits: int}>,
     *     missed: list<array{url: string, clicks: int}>,
     *     missedChecked: int
     * }
     */
    public function report(Project $project, BotLogImport $import, string $bot): array
    {
        $bots = $this->logs->botTotals($import->id);
        if (!isset($bots[$bot])) {
            $bot = array_key_first($bots) ?? 'googlebot';
        }
        // Pages that earned Google clicks in the 28 days up to the log's end (or today) but that the bot never asked for.
        $end = $import->lastSeen !== null ? new \DateTimeImmutable(substr($import->lastSeen, 0, 10)) : new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));
        $pages = $this->search->top($project->id, PerformanceDimension::Page, new DateRange($end->modify('-27 days')->format('Y-m-d'), $end->format('Y-m-d')), 200);
        $byPath = [];
        foreach ($pages as $row) {
            $parts = parse_url($row->key);
            if (is_array($parts) && isset($parts['host'])) {
                $byPath[($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '')] = ['url' => $row->key, 'clicks' => $row->metrics->clicks];
            }
        }
        $requested = $byPath === [] ? [] : $this->logs->hitsFor($import->id, $bot, array_map('strval', array_keys($byPath)));
        $missed = [];
        foreach ($byPath as $path => $page) {
            if (!isset($requested[(string) $path]) && $page['clicks'] > 0) {
                $missed[] = $page;
            }
        }

        return [
            'bots' => $bots,
            'bot' => $bot,
            'top' => $this->logs->topPaths($import->id, $bot, 25),
            'errors' => $this->logs->topPaths($import->id, $bot, 25, 400, 599),
            'redirects' => $this->logs->topPaths($import->id, $bot, 15, 300, 399),
            'parameters' => $this->logs->topPaths($import->id, $bot, 25, null, null, true),
            'missed' => array_slice($missed, 0, 25),
            'missedChecked' => count($byPath),
        ];
    }

    /** The effective upload limit in MB: the smaller of MAX_MB and PHP's upload limits. */
    public static function uploadLimitMb(): int
    {
        $toMb = static function (string $value): int {
            $value = trim($value);
            $number = (int) $value;

            return match (strtolower(substr($value, -1))) {
                'g' => $number * 1024,
                'm' => $number,
                'k' => intdiv($number, 1024),
                default => intdiv($number, 1048576),
            };
        };
        $limits = array_filter([$toMb((string) ini_get('upload_max_filesize')), $toMb((string) ini_get('post_max_size')), self::MAX_MB], static fn(int $v): bool => $v > 0);

        return $limits === [] ? self::MAX_MB : min($limits);
    }

    /** gzip, or text without NUL bytes in the first 8 KB. */
    private static function readable(string $path): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 8192);

        return str_starts_with($head, "\x1f\x8b") || ($head !== '' && !str_contains($head, "\0"));
    }

    private function path(BotLogImport $import): string
    {
        return $this->directory . '/' . $import->publicId . '.log';
    }

    private function remove(BotLogImport $import): void
    {
        $path = $this->path($import);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('The log directory cannot be created.');
        }
    }
}

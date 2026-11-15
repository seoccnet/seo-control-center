<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Domain\BotLogs\LogLineParser;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\BotLogs\BotLogImport;
use SeoControlCenter\Modules\BotLogs\BotLogService;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;

/**
 * bot_logs (1.2.1): what search and AI crawlers requested, from uploaded
 * access logs. Read-only: never uploads or deletes logs.
 */
final readonly class BotLogsTool implements McpTool
{
    public function __construct(private BotLogService $logs) {}

    public function name(): string
    {
        return 'bot_logs';
    }

    public function title(): string
    {
        return 'Search bot logs';
    }

    public function description(): string
    {
        return 'What search and AI crawlers requested, from access logs uploaded in the app (Improve → Bot logs). logs lists the newest uploads with period, lines and crawler requests. The report is for log_id, or else the newest finished log, and for bot (default googlebot, or the busiest crawler in the log): crawlers lists every crawler with requests, distinct paths and requests by status class and with parameters, plus verified requests where the crawler publishes its IP ranges (Googlebot, Bingbot; null when ranges were unavailable); for the chosen bot: errors (4xx/5xx paths), parameters (URLs with a query string), redirects, top (most requested paths) and missed (pages among the 200 with the most Google clicks in the 28 days up to the log\'s end that the bot never requested). Totals per path and status only: no IP addresses or single requests are stored. Crawlers are recognised by user agent; unverified requests may be other tools using a crawler\'s name. A short log can miss pages crawled less often.';
    }

    public function scope(): McpScope
    {
        return McpScope::Pages;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'log_id' => ['type' => 'string', 'maxLength' => 36, 'description' => 'A log id from logs; default the newest finished log.'],
            'bot' => ['type' => 'string', 'enum' => array_keys(LogLineParser::BOTS), 'default' => 'googlebot'],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $imports = $this->logs->list($project);
        $logId = $arguments['log_id'] ?? null;
        if ($logId !== null && !is_string($logId)) {
            throw new ToolError('log_id must be text.');
        }
        $selected = null;
        if (is_string($logId) && $logId !== '') {
            try {
                $selected = $this->logs->get($project, $logId);
            } catch (NotFoundHttpException) {
                throw new ToolError('Unknown log_id. Call bot_logs without log_id for the list.');
            }
        } else {
            foreach ($imports as $import) {
                if ($import->status === BotLogImport::DONE) {
                    $selected = $import;
                    break;
                }
            }
        }
        $bot = is_string($arguments['bot'] ?? null) && isset(LogLineParser::BOTS[$arguments['bot']]) ? $arguments['bot'] : 'googlebot';
        $report = $selected !== null && $selected->status === BotLogImport::DONE ? $this->logs->report($project, $selected, $bot) : null;

        return [
            'project_id' => $project->publicId,
            'logs' => array_map(self::import(...), $imports),
            'log' => $selected !== null ? self::import($selected) : null,
            'report' => $report === null ? null : [
                'bot' => $report['bot'],
                'bot_label' => LogLineParser::label($report['bot']),
                'crawlers' => array_map(static fn(string $key, array $t): array => ['bot' => $key, 'label' => LogLineParser::label($key), ...$t], array_keys($report['bots']), array_values($report['bots'])),
                'errors' => $report['errors'],
                'parameters' => $report['parameters'],
                'redirects' => $report['redirects'],
                'top' => $report['top'],
                'missed' => $report['missed'],
                'missed_checked' => $report['missedChecked'],
            ],
            'notice' => $selected === null ? 'No finished log yet. Logs are uploaded in the app under Improve → Bot logs.' : 'Crawlers are recognised by user agent; only verified counts are confirmed by the crawler\'s published IP ranges. Totals cover only the uploaded period.',
        ];
    }

    /** @return array<string, mixed> */
    private static function import(BotLogImport $import): array
    {
        return [
            'id' => $import->publicId,
            'file_name' => $import->fileName,
            'status' => $import->status,
            'first_request' => $import->firstSeen,
            'last_request' => $import->lastSeen,
            'lines' => $import->linesRead,
            'crawler_requests' => $import->botLines,
            'dropped_requests' => $import->droppedHits,
            'error' => $import->error,
            'uploaded_at' => $import->createdAt,
        ];
    }
}

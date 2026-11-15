<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\BotLogs\LogLineParser;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\BotLogs\BotLogService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Search bot log analysis (1.2.0): upload, list and report. */
final readonly class BotLogController
{
    public function __construct(
        private ProjectService $projects,
        private BotLogService $logs,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $imports = $this->logs->list($project);
        $query = $request->getQueryParams();
        $selected = null;
        if (is_string($query['log'] ?? null)) {
            $selected = $this->logs->get($project, $query['log']);
        } else {
            foreach ($imports as $import) {
                if ($import->status === 'done') {
                    $selected = $import;
                    break;
                }
            }
        }
        $bot = is_string($query['bot'] ?? null) && isset(LogLineParser::BOTS[$query['bot']]) ? $query['bot'] : 'googlebot';

        return $this->responses->html($this->views->render('bot-logs/index', [
            'project' => $project,
            'imports' => $imports,
            'selected' => $selected,
            'report' => $selected !== null && $selected->status === 'done' ? $this->logs->report($project, $selected, $bot) : null,
            'canEdit' => $this->logs->canEdit(WebContext::user($request), $project),
            'limitMb' => BotLogService::uploadLimitMb(),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function upload(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $base = $this->base($project);
        $this->logs->authorizeUpload(WebContext::user($request), $project);
        $file = $request->getUploadedFiles()['log'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            WebContext::session($request)->flash('error', match ($file instanceof UploadedFileInterface ? $file->getError() : UPLOAD_ERR_NO_FILE) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => sprintf('The file is larger than this server accepts (%d MB). Compress it with gzip or upload a shorter period.', BotLogService::uploadLimitMb()),
                UPLOAD_ERR_NO_FILE => 'Choose a log file to upload.',
                default => 'The upload did not complete. Try again.',
            });

            return $this->responses->redirect($base);
        }
        $path = $this->logs->incomingPath();
        try {
            $file->moveTo($path);
            $this->logs->accept(WebContext::user($request), $project, $path, (string) $file->getClientFilename(), ClientInfo::fromRequest($request));
        } catch (HttpException $e) {
            if (!in_array($e->statusCode(), [422, 429], true)) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());

            return $this->responses->redirect($base);
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        WebContext::session($request)->flash('status', 'Log uploaded. Background tasks read it; the report appears here when they finish.');

        return $this->responses->redirect($base);
    }

    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $import = $this->logs->get($project, WebContext::routeParam($request, 'log'));
        $this->logs->delete(WebContext::user($request), $project, $import, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', 'Log report deleted.');

        return $this->responses->redirect($this->base($project));
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }

    private function base(Project $project): string
    {
        return '/projects/' . $project->publicId . '/bot-logs';
    }
}

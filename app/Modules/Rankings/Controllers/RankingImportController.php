<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Rankings\RankingImportService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/** Import of keyword lists and ranking history from other tools (1.3.0). */
final readonly class RankingImportController
{
    private const string RESULT_FLASH = 'ranking_import';

    public function __construct(
        private ProjectService $projects,
        private RankingImportService $imports,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private Clock $clock,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $result = WebContext::session($request)->getFlash(self::RESULT_FLASH);

        return $this->responses->html($this->views->render('rankings/import', [
            'project' => $project,
            'markets' => $this->imports->markets($project),
            'canImport' => $this->imports->canImport(WebContext::user($request), $project),
            'today' => $this->clock->now()->format('Y-m-d'),
            'result' => is_string($result) ? json_decode($result, true) : null,
            'maxMb' => intdiv(RankingImportService::MAX_BYTES, 1048576),
        ], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function import(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $back = '/projects/' . $project->publicId . '/rankings/import';
        $user = WebContext::user($request);
        if (!$this->imports->canImport($user, $project)) {
            throw new HttpException(403, 'You cannot import into this project.');
        }
        $body = (array) $request->getParsedBody();
        $file = $request->getUploadedFiles()['file'] ?? null;
        $bytes = $file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK && (int) $file->getSize() <= RankingImportService::MAX_BYTES
            ? (string) $file->getStream()
            : '';
        try {
            $result = $this->imports->import(
                $user,
                $project,
                $bytes,
                is_string($body['market'] ?? null) ? $body['market'] : '',
                is_string($body['device'] ?? null) ? $body['device'] : '',
                is_string($body['date'] ?? null) ? $body['date'] : '',
                ClientInfo::fromRequest($request),
            );
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));

            return $this->responses->redirect($back);
        } catch (HttpException $e) {
            if ($e->statusCode() !== 429) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());

            return $this->responses->redirect($back);
        }
        WebContext::session($request)->flash(self::RESULT_FLASH, (string) json_encode($result));
        WebContext::session($request)->flash('status', 'Import finished.');

        return $this->responses->redirect($back);
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}

<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Comparison;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Keywords → Topics & owners → a topic's "Why they outrank you" analysis.
 */
final readonly class ComparisonController
{
    public function __construct(private ProjectService $projects, private ComparisonService $comparisons, private ResponseFactory $responses, private ViewRenderer $views, private ?ComparisonReport $reports = null) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $state = $this->comparisons->prepare(WebContext::user($request), $project, WebContext::routeParam($request, 'cluster'));

        return $this->responses->html($this->views->render('comparison/show', ['project' => $project, ...$state], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function start(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->project($request);
        $clusterId = WebContext::routeParam($request, 'cluster');
        $body = WebContext::body($request);
        $path = '/projects/' . $project->publicId . '/keywords/topics/' . $clusterId . '/analysis';
        try {
            $this->comparisons->request(WebContext::user($request), $project, $clusterId, ($body['confirm_cost'] ?? null) === '1', ($body['rerun'] ?? null) === '1', ClientInfo::fromRequest($request), \SeoControlCenter\Modules\Shared\Http\RepeatConfirmation::requested($request));
            WebContext::session($request)->flash('status', 'Analysis queued. It runs at the next cron run and fetches up to five competing pages; this page updates itself.');
        } catch (\SeoControlCenter\Modules\Research\RepeatedLookup $e) {
            \SeoControlCenter\Modules\Shared\Http\RepeatConfirmation::flash($request, $e->getMessage());
        } catch (HttpException $e) {
            if (!in_array($e->statusCode(), [409, 422], true)) {
                throw $e;
            }
            WebContext::session($request)->flash('error', $e->getMessage());
        }

        return $this->responses->redirect($path);
    }

    /** The client report of the latest finished analysis as a PDF download. */
    public function report(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->reports === null) {
            throw new HttpException(404, 'Not found.');
        }
        $project = $this->project($request);
        $pdf = $this->reports->pdf(WebContext::user($request), $project, WebContext::routeParam($request, 'cluster'), ClientInfo::fromRequest($request));

        return $this->responses->text($pdf['content'])
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $pdf['fileName'] . '"')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    private function project(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}

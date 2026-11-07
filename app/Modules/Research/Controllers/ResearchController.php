<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Research\RepeatedLookup;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Modules\Research\ResearchTable;
use SeoControlCenter\Modules\Shared\Http\RepeatConfirmation;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Project → Keywords → Keyword research.
 */
final readonly class ResearchController
{
    private const array SECTIONS = ['overview', 'ranked', 'gap', 'ideas', 'competitors', 'suggestions', 'discover'];

    public function __construct(
        private ResearchService $service,
        private ProjectService $projects,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private ?\SeoControlCenter\Modules\Research\ResearchOnFile $onFile = null,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->page($request, $this->load($request), [], [], 200);
    }

    /** Research on file: the paid lookups and analyses this project already has. */
    public function onFile(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        if ($this->onFile === null) {
            throw new \SeoControlCenter\Core\Http\Exception\HttpException(404, 'Not found.');
        }

        return $this->responses->html($this->views->render('research/on-file', ['project' => $project, ...$this->onFile->list($project)], $request))->withHeader('Cache-Control', 'no-store');
    }

    public function fetch(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $kind = is_string($body['kind'] ?? null) ? $body['kind'] : '';
        $field = $kind === 'llm_answers' ? 'prompts' : 'seeds';
        $seeds = is_string($body[$field] ?? null) ? $body[$field] : '';
        try {
            $dropped = $this->service->request(
                WebContext::user($request),
                $project,
                $kind,
                is_string($body['competitor'] ?? null) ? $body['competitor'] : null,
                $seeds,
                $body['location'] ?? null,
                $body['language'] ?? null,
                ClientInfo::fromRequest($request),
                is_string($body['brand'] ?? null) ? $body['brand'] : '',
                is_string($body['modifiers'] ?? null) ? $body['modifiers'] : '',
                ($body['confirm'] ?? null) === '1',
                RepeatConfirmation::requested($request),
            );
        } catch (RepeatedLookup $e) {
            RepeatConfirmation::flash($request, $e->getMessage());

            return $this->back($project, $kind, is_string($body['competitor'] ?? null) ? $body['competitor'] : null, $body);
        } catch (ValidationException $e) {
            if ($kind === 'llm_answers') {
                WebContext::session($request)->flash('error', implode(' ', $e->errors()));

                return $this->back($project, $kind, null);
            }
            $errors = $kind === 'suggestions' && isset($e->errors()['seeds']) ? ['suggestion_seeds' => $e->errors()['seeds'], ...array_diff_key($e->errors(), ['seeds' => true])] : $e->errors();

            return $this->page($request, $project, $errors, $kind === 'suggestions' ? ['suggestion_seeds' => $seeds, 'modifiers' => is_string($body['modifiers'] ?? null) ? $body['modifiers'] : ''] : ['seeds' => $seeds], 422);
        }
        WebContext::session($request)->flash('status', 'Lookup queued. The result appears here after the next cron run; this page updates itself.' . ($dropped === [] ? '' : ' Left out because they clash with your business profile: ' . implode('; ', array_map(static fn(array $d): string => '“' . $d['seed'] . '” (' . $d['reason'] . ')', $dropped)) . '. Tick “Run anyway” to include them.'));

        return $this->back($project, $kind, is_string($body['competitor'] ?? null) ? $body['competitor'] : null, $body);
    }

    /** Marks a research keyword relevant or not relevant for the business (a team rating), or back to automatic. */
    public function relevance(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        // Table rows send one button value: verdict|section|keyword.
        if (is_string($body['kw_relevance'] ?? null)) {
            [$body['verdict'], $body['section'], $body['keyword']] = array_pad(explode('|', $body['kw_relevance'], 3), 3, '');
        }
        $section = is_string($body['section'] ?? null) ? $body['section'] : '';
        try {
            $verdict = $this->service->setRelevance(WebContext::user($request), $project, is_string($body['keyword'] ?? null) ? $body['keyword'] : '', is_string($body['verdict'] ?? null) ? $body['verdict'] : '', ClientInfo::fromRequest($request));
            WebContext::session($request)->flash('status', match ($verdict) {
                'relevant' => 'Marked relevant. Opportunities use it from the next analysis.',
                'not_relevant' => 'Marked not relevant. It is hidden from research tables and scores 0 in opportunities.',
                default => 'Relevance set back to automatic.',
            });
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));
        }

        return $this->back($project, $section, is_string($body['competitor'] ?? null) ? $body['competitor'] : null);
    }

    public function seedIdeas(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        try {
            $this->service->suggestSeeds(WebContext::user($request), $project, $body['location'] ?? null, $body['language'] ?? null, ClientInfo::fromRequest($request));
            WebContext::session($request)->flash('status', 'Seed suggestions queued. They appear here after the next cron run; this page updates itself.');
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));
        }

        return $this->back($project, 'discover', null);
    }

    public function track(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $body = WebContext::body($request);
        $keywords = is_array($body['keywords'] ?? null) ? array_values(array_filter($body['keywords'], is_string(...))) : [];
        $section = is_string($body['section'] ?? null) ? $body['section'] : '';
        try {
            $added = $this->service->track(WebContext::user($request), $project, $keywords, $body['location'] ?? null, $body['language'] ?? null, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            WebContext::session($request)->flash('error', implode(' ', $e->errors()));

            return $this->back($project, $section, is_string($body['competitor'] ?? null) ? $body['competitor'] : null);
        }
        WebContext::session($request)->flash('status', $added === 0
            ? 'These keywords are already tracked.'
            : sprintf('%d keyword%s added to the tracked keywords.', $added, $added === 1 ? '' : 's'));

        return $this->back($project, $section, is_string($body['competitor'] ?? null) ? $body['competitor'] : null);
    }

    public function weeklyMentions(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $weekly = (WebContext::body($request)['weekly'] ?? null) === '1';
        $this->service->setWeeklyMentions(WebContext::user($request), $project, $weekly, ClientInfo::fromRequest($request));
        WebContext::session($request)->flash('status', $weekly ? 'AI mentions are checked every week from now on.' : 'The weekly AI mention check is off.');

        return $this->responses->redirect('/projects/' . $project->publicId . '/ai-visibility#assistants');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function page(ServerRequestInterface $request, Project $project, array $errors, array $old, int $status): ResponseInterface
    {
        $query = $request->getQueryParams();
        $competitor = $query['competitor'] ?? null;
        $data = $this->service->page(WebContext::user($request), $project);
        try {
            $market = isset($query['location'], $query['language']) ? KeywordLocale::fromInput($query['location'], $query['language']) : $data['locale'];
        } catch (ValidationException) {
            $market = $data['locale'];
        }

        $tables = [
            'overview' => ResearchTable::fromQuery($query, 'o', ResearchTable::DOMAIN_COLUMNS, 'traffic', 'desc'),
            'ranked' => ResearchTable::fromQuery($query, 'r', ResearchTable::KEYWORD_COLUMNS, 'traffic', 'desc'),
            'gap' => ResearchTable::fromQuery($query, 'g', ResearchTable::KEYWORD_COLUMNS, 'volume', 'desc'),
            'ideas' => ResearchTable::fromQuery($query, 'i', ResearchTable::KEYWORD_COLUMNS, 'volume', 'desc'),
            'suggestions' => ResearchTable::fromQuery($query, 's', ResearchTable::KEYWORD_COLUMNS, 'volume', 'desc'),
            'competitors' => ResearchTable::fromQuery($query, 'c', ResearchTable::DOMAIN_COLUMNS, 'shared', 'desc'),
        ];
        // What every link and filter form on the page keeps: the market, the competitor and each table's state.
        $state = array_filter([
            'competitor' => is_string($competitor) ? $competitor : null,
            'location' => isset($query['location']) ? (string) $market->locationCode : null,
            'language' => isset($query['language']) ? $market->languageCode : null,
        ], static fn(?string $v): bool => $v !== null);
        foreach ($tables as $table) {
            if (isset($query[$table->prefix . '_sort']) || $table->hasFilters()) {
                $state += $table->params();
            }
        }

        return $this->responses->html($this->views->render('research/index', [
            'project' => $project,
            ...$data,
            'market' => $market,
            'outsideMarket' => $data['business'] !== null && !$data['business']->servesMarket($market),
            'tables' => $tables,
            'state' => $state,
            'selectedCompetitor' => is_string($competitor) ? $competitor : null,
            'errors' => $errors,
            'old' => $old,
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }

    /** @param array<string, mixed> $body the submitted form, for the market it was started in */
    private function back(Project $project, string $section, ?string $competitor, array $body = []): ResponseInterface
    {
        if (in_array($section, ['llm', 'llm_prompts', 'llm_answers'], true)) {
            return $this->responses->redirect('/projects/' . $project->publicId . '/ai-visibility#assistants');
        }
        if ($section === 'serp_strength') {
            return $this->responses->redirect('/projects/' . $project->publicId . '/opportunities/business#strength');
        }
        $section = $section === 'suggestions' ? 'discover' : $section;
        $params = [];
        if ($section === 'gap' && $competitor !== null && preg_match('/^[0-9a-f-]{36}$/', $competitor) === 1) {
            $params['competitor'] = $competitor;
        }
        // Back in the market the lookup was started in, not the project's last one.
        if (is_string($body['location'] ?? null) && ctype_digit($body['location']) && is_string($body['language'] ?? null) && preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/i', $body['language']) === 1) {
            $params['location'] = $body['location'];
            $params['language'] = $body['language'];
        }
        $query = $params === [] ? '' : '?' . http_build_query($params);

        return $this->responses->redirect('/projects/' . $project->publicId . '/keywords/research' . $query . (in_array($section, self::SECTIONS, true) ? '#' . $section : ''));
    }

    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}

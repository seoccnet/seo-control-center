<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Tasks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Shared\Http\WebContext;

final readonly class TasksController
{
    public function __construct(private TaskService $tasks, private ProjectService $projects, private ResponseFactory $responses, private ViewRenderer $views, private \SeoControlCenter\Modules\Opportunities\OpportunityService $opportunities) {}
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        $query = $request->getQueryParams();
        return $this->responses->html($this->views->render('opportunities/tasks', ['project' => $project, 'assignees' => $this->tasks->assignees($project), ...$this->tasks->listing($project, TaskFilter::fromArray($query), is_string($query['page'] ?? null) && ctype_digit($query['page']) ? (int) $query['page'] : 1)], $request))->withHeader('Cache-Control', 'no-store');
    }
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->form($request);
    }
    public function save(ServerRequestInterface $request): ResponseInterface
    {
        $project = $this->load($request);
        try {
            $this->tasks->save(WebContext::user($request), $project, WebContext::routeParam($request, 'opportunity'), WebContext::body($request), ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->form($request, implode(' ', $e->errors()), 422);
        } catch (HttpException $e) {
            if (!in_array($e->statusCode(), [409, 422], true)) {
                throw $e;
            } return $this->form($request, $e->getMessage(), $e->statusCode());
        }
        WebContext::session($request)->flash('status', 'Task saved. The SEO finding status is unchanged.');
        return $this->responses->redirect('/projects/' . $project->publicId . '/opportunities/tasks');
    }
    private function form(ServerRequestInterface $request, string $error = '', int $status = 200): ResponseInterface
    {
        $project = $this->load($request);
        $id = WebContext::routeParam($request, 'opportunity');
        $task = $this->tasks->get($project, $id);
        $input = $task === null ? new TaskInput() : $task->input;
        $data = $input->form();
        if ($error !== '') {
            foreach (WebContext::body($request) as $key => $value) {
                if (array_key_exists($key, $data) && is_string($value)) {
                    $data[$key] = mb_substr($value, 0, 5000);
                }
            }
        }
        $opportunity = $this->tasks->opportunity($project, $id);
        return $this->responses->html($this->views->render('opportunities/task', ['project' => $project, 'opportunity' => $opportunity, 'action_plan' => $this->opportunities->planFor($opportunity), 'task' => $task, 'data' => $data, 'choices' => $this->tasks->choices($project, $input), 'links' => $this->tasks->links($project, $input), 'assignees' => $this->tasks->assignees($project), 'canEdit' => $this->tasks->canEdit(WebContext::user($request), $project), 'error' => $error, 'conflict' => $status === 409], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
    private function load(ServerRequestInterface $request): Project
    {
        return $this->projects->get(WebContext::user($request), WebContext::workspace($request)->workspace(), WebContext::routeParam($request, 'project'));
    }
}

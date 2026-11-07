<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests;

use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Session\Session;
use SeoControlCenter\Core\View\LayoutWidget;
use SeoControlCenter\Core\View\LayoutWidgets;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Workspaces\CurrentWorkspace;

/**
 * The compact Quest Log card on the dashboard and project overview: the
 * current quest, its next step and the "Needs attention" marker. It can be
 * hidden; it never opens on its own and never queues work.
 */
final readonly class QuestWidget implements LayoutWidget
{
    public function __construct(private QuestLogs $logs, private string $slot) {}

    public function render(\SeoControlCenter\Core\View\Template $view): ?string
    {
        $user = $view->attribute(RequestAttribute::USER);
        $context = $view->attribute(RequestAttribute::WORKSPACE);
        $session = $view->attribute(RequestAttribute::SESSION);
        if (!$user instanceof User || !$context instanceof CurrentWorkspace || !$session instanceof Session) {
            return null;
        }
        $workspace = $context->workspace();
        // Checked first: a hidden card does no work and leaves new quests and rank toasts for the Quest Log.
        if ($this->logs->hidden($user, $workspace)) {
            return null;
        }
        $projects = $this->logs->projects($user, $workspace);
        if ($this->slot === LayoutWidgets::PROJECT_OVERVIEW) {
            $params = $view->attribute(RequestAttribute::ROUTE_PARAMS);
            $id = is_array($params) ? ($params['project'] ?? null) : null;
            $project = null;
            foreach ($projects as $candidate) {
                if ($candidate->publicId === $id) {
                    $project = $candidate;
                }
            }
            if ($project === null) {
                return null;
            }
        } else {
            $project = $this->logs->selected($session, $workspace, $projects);
        }
        $log = $this->logs->build($user, $workspace, $projects, $project);

        return $view->partial('partials/quest-widget', ['log' => $log, 'project' => $project, 'return' => $view->path()]);
    }
}

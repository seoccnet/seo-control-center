<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System;

use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\View\LayoutWidget;
use SeoControlCenter\Core\View\Template;
use SeoControlCenter\Domain\Accounts\User;

/** Top-bar notice for administrators when the optional update check found a newer version (ADR-053). */
final readonly class UpdateCheckWidget implements LayoutWidget
{
    public function __construct(private UpdateCheck $check, private Authorizer $auth) {}

    public function render(Template $view): ?string
    {
        $user = $view->attribute(RequestAttribute::USER);
        if (!$user instanceof User || !$this->auth->can($user->subject(), Permission::SystemUpdate)) {
            return null;
        }
        $status = $this->check->status();
        if (!$status['enabled'] || !$status['newer'] || $status['latest'] === null) {
            return null;
        }

        return '<a class="badge badge-info" href="/system/updates#update-check">' . $view->e('SEOcc ' . $status['latest']['version'] . ' is available') . '</a>';
    }
}

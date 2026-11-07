<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO;

use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\LayoutWidget;
use SeoControlCenter\Core\View\Template;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Integrations\SpendForecast;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Workspaces\CurrentWorkspace;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;

/**
 * DataForSEO spending in the top bar: this month and the month-end
 * forecast at a glance, with today, last month, the budget, the account
 * balance and the spending per feature in its panel. Shown to members who
 * can start paid analyses in a workspace with DataForSEO connected.
 */
final readonly class SpendWidget implements LayoutWidget
{
    private const array CATEGORIES = [
        'serp_' => 'Rank tracking',
        'keyword_metrics' => 'Keyword metrics',
        'labs_' => 'Keyword research',
        'llm_' => 'AI mentions',
        'local_' => 'Local SEO',
        'backlinks_' => 'Backlinks',
    ];

    public function __construct(
        private DataForSeoProfileRepository $profiles,
        private UsageLedger $ledger,
        private Authorizer $authorizer,
        private Clock $clock,
    ) {}

    public function render(Template $view): ?string
    {
        $user = $view->attribute(RequestAttribute::USER);
        $context = $view->attribute(RequestAttribute::WORKSPACE);
        if (!$user instanceof User || !$context instanceof CurrentWorkspace) {
            return null;
        }
        $workspaceId = $context->id();
        if (!$this->authorizer->can($user->subject(), Permission::AnalysisExecute, $workspaceId)) {
            return null;
        }
        $profile = $this->profiles->find($workspaceId);
        if ($profile === null) {
            return null;
        }
        $now = $this->clock->now();
        $forecast = SpendForecast::from(
            $this->ledger->dailySpend($workspaceId, DataForSeoClient::PROVIDER, $now->modify('first day of last month')->format('Y-m-d')),
            $now,
            $this->ledger->budget($workspaceId, DataForSeoClient::PROVIDER),
            $profile->balance,
            self::category(...),
        );

        return $view->partial('integrations/spend-widget', [
            'forecast' => $forecast,
            'balanceCheckedAt' => $profile->checkedAt,
            'canManage' => $this->authorizer->can($user->subject(), Permission::IntegrationsManage, $workspaceId),
        ]);
    }

    public static function category(string $operation): string
    {
        foreach (self::CATEGORIES as $prefix => $label) {
            if (str_starts_with($operation, $prefix)) {
                return $label;
            }
        }

        return ucfirst(str_replace('_', ' ', $operation));
    }
}

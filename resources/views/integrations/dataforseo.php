<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Modules\DataForSEO\DataForSeoProfile|null $profile
 * @var SeoControlCenter\Domain\Integrations\ApiBudget $budget
 * @var SeoControlCenter\Domain\Integrations\UsageSummary $usage
 * @var bool $canManage
 * @var bool $sandbox
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
use SeoControlCenter\Domain\Integrations\BudgetPolicy;

$view->extend('layouts/app', ['title' => 'DataForSEO']);
$money = static fn(?float $amount): string => $amount === null ? '' : BudgetPolicy::money($amount);
$limit = static fn(?float $amount): string => $amount === null ? 'No limit' : '$' . BudgetPolicy::money($amount);
$value = static fn(string $field, ?float $saved): string => $old[$field] ?? $money($saved);
?>
<div class="page-header">
    <h1>DataForSEO</h1>
    <a class="button button-ghost" href="/integrations">All integrations</a>
</div>
<?= $view->partial('partials/integration-guide', ['guide' => 'dataforseo', 'open' => false]) ?>

<?php if ($sandbox): ?>
    <p class="notice notice-success">Sandbox mode: requests go to DataForSEO's free sandbox, which returns sample data and is never charged.</p>
<?php endif; ?>

<section class="card">
    <h2>Account</h2>
    <p class="muted">DataForSEO provides keyword metrics and search results. You use your own DataForSEO account and pay DataForSEO directly; <?= $view->e(isset($brand) ? $brand()->name($appName ?? '') : 'SEO Control Center') ?> only sends requests you start or schedule, within the budgets below.</p>

    <?php if ($profile !== null): ?>
        <dl class="details">
            <dt>API login</dt>
            <dd>
                <?= $view->e($profile->login) ?>
                <?php if ($profile->isActive()): ?>
                    <span class="badge badge-ok">Connected</span>
                <?php else: ?>
                    <span class="badge badge-error">Access refused</span>
                <?php endif; ?>
            </dd>
            <dt>Balance</dt>
            <dd><?= $profile->balance !== null ? '$' . $view->e($money($profile->balance)) : '—' ?><?php if ($profile->checkedAt !== null): ?> <span class="muted small">checked <?= $view->e($profile->checkedAt) ?> UTC</span><?php endif; ?></dd>
        </dl>
        <?php if (!$profile->isActive() && $profile->lastError !== null): ?>
            <p class="notice notice-error"><?= $view->e($profile->lastError) ?></p>
            <p class="muted small">Fix the cause in your DataForSEO account (for example account verification or a changed API password), then press <em>Test connection</em>. Enter new credentials below only if the API password changed.</p>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <div class="actions">
                <form method="post" action="/integrations/dataforseo/verify" class="inline-form">
                    <?= $view->csrfField() ?>
                    <button type="submit" class="button button-small button-ghost">Test connection</button>
                </form>
                <form method="post" action="/integrations/dataforseo/disconnect" class="inline-form">
                    <?= $view->csrfField() ?>
                    <button type="submit" class="button button-small button-danger">Disconnect</button>
                </form>
            </div>
        <?php endif; ?>
    <?php elseif (!$canManage): ?>
        <p class="muted">Not connected. A workspace owner or admin can connect a DataForSEO account.</p>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <h3><?= $profile === null ? 'Connect' : 'Replace credentials' ?></h3>
        <p class="muted small">Use the <strong>API login and API password</strong> from <em>DataForSEO dashboard → API Access</em>. The password is checked with DataForSEO, then stored encrypted and never shown again.</p>
        <form method="post" action="/integrations/dataforseo" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['login']) ? ' has-error' : '' ?>">
                <label for="login">API login</label>
                <input id="login" name="login" type="text" required maxlength="191" autocomplete="off" value="<?= $view->e($old['login'] ?? ($profile->login ?? '')) ?>"<?= $view->invalid($errors, 'login', 'login') ?>>
                <?= $view->fieldError($errors, 'login', 'login') ?>
            </div>
            <div class="field<?= isset($errors['password']) ? ' has-error' : '' ?>">
                <label for="password">API password</label>
                <input id="password" name="password" type="password" required maxlength="255" autocomplete="new-password"<?= $view->invalid($errors, 'password', 'password') ?>>
                <?= $view->fieldError($errors, 'password', 'password') ?>
            </div>
            <button type="submit" class="button"><?= $profile === null ? 'Connect DataForSEO' : 'Save new credentials' ?></button>
        </form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Usage</h2>
    <div class="kpis">
        <div class="kpi">
            <span class="kpi-label">Today (UTC)</span>
            <span class="kpi-value">$<?= $view->e($money($usage->today)) ?></span>
            <span class="kpi-note"><?= $view->e($usage->requestsToday) ?> request<?= $usage->requestsToday === 1 ? '' : 's' ?> · budget <?= $view->e($limit($budget->dailyLimit)) ?></span>
        </div>
        <div class="kpi">
            <span class="kpi-label">This month (UTC)</span>
            <span class="kpi-value">$<?= $view->e($money($usage->month)) ?></span>
            <span class="kpi-note"><?= $view->e($usage->requestsMonth) ?> request<?= $usage->requestsMonth === 1 ? '' : 's' ?> · budget <?= $view->e($limit($budget->monthlyLimit)) ?></span>
        </div>
        <div class="kpi">
            <span class="kpi-label">Per request</span>
            <span class="kpi-value"><?= $view->e($limit($budget->operationLimit)) ?></span>
            <span class="kpi-note">maximum estimated cost</span>
        </div>
    </div>
    <p class="muted small">Costs are the amounts DataForSEO reports for each request. Requests that would exceed a budget are not sent.</p>
</section>

<?php if ($canManage): ?>
    <section class="card">
        <h2>Budgets</h2>
        <form method="post" action="/integrations/dataforseo/budget" class="form" novalidate>
            <?= $view->csrfField() ?>
            <?php foreach ([
                'daily_limit' => ['Daily budget (USD)', $budget->dailyLimit],
                'monthly_limit' => ['Monthly budget (USD)', $budget->monthlyLimit],
                'operation_limit' => ['Maximum per request (USD)', $budget->operationLimit],
            ] as $field => [$label, $saved]): ?>
                <div class="field<?= isset($errors[$field]) ? ' has-error' : '' ?>">
                    <label for="<?= $view->e($field) ?>"><?= $view->e($label) ?></label>
                    <input id="<?= $view->e($field) ?>" name="<?= $view->e($field) ?>" type="text" inputmode="decimal" maxlength="12" placeholder="No limit" value="<?= $view->e($value($field, $saved)) ?>"<?= $view->invalid($errors, $field, $field) ?>>
                    <?= $view->fieldError($errors, $field, $field) ?>
                </div>
            <?php endforeach; ?>
            <button type="submit" class="button">Save budgets</button>
        </form>
    </section>
<?php endif; ?>

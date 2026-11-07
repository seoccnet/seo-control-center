<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Modules\Assistant\AssistantSettings|null $settings
 * @var array<string, string> $providers  provider => label
 * @var array<string, array<string, string>> $models  provider => model id => label
 * @var SeoControlCenter\Domain\Integrations\ApiBudget $budget
 * @var SeoControlCenter\Domain\Integrations\UsageSummary $usage
 * @var bool $canManage
 * @var bool $featureOn
 * @var array<string, string> $errors
 * @var array<array-key, mixed> $old
 */
use SeoControlCenter\Domain\Integrations\BudgetPolicy;

$view->extend('layouts/app', ['title' => 'AI assistant']);
$amount = static fn(?float $value): string => $value === null ? '' : BudgetPolicy::money($value);
$value = static fn(string $field, ?float $saved): string => isset($old[$field]) && is_string($old[$field]) ? $old[$field] : $amount($saved);
$currentModel = $settings->model ?? null;
$provider = $settings->provider ?? null;
$allModels = array_merge(...array_values($models));
$name = $provider === 'openai' ? 'OpenAI' : 'Claude';
?>
<div class="page-header">
    <h1>AI assistant</h1>
    <a class="button button-ghost" href="/integrations">All integrations</a>
</div>
<?= $view->partial('partials/integration-guide', ['guide' => 'assistant', 'open' => false]) ?>

<?php if (!$featureOn): ?>
    <p class="notice notice-warning">AI access, which the assistant needs, is turned off on this installation.</p>
<?php endif; ?>

<section class="card">
    <h2>Claude or GPT</h2>
    <p class="muted">The <strong>Ask AI</strong> chat at the bottom right of every page answers questions about this workspace's SEO data: performance, keywords, rankings, competitors, opportunities, the site audit, keyword research, backlinks, AI visibility and local SEO. It reads stored data only, and it can mark opportunities as done or dismissed after you confirm. It uses your own API key from Anthropic (Claude) or OpenAI (GPT); the provider bills you directly, within the budgets below.</p>

    <?php if ($settings !== null): ?>
        <dl class="details">
            <dt>Status</dt>
            <dd><?php if ($settings->isActive()): ?><span class="badge badge-ok">Ready</span><?php else: ?><span class="badge badge-error">Key refused</span><?php endif; ?></dd>
            <dt>Model</dt>
            <dd><?= $view->e($allModels[$settings->model] ?? $settings->model) ?> <span class="muted small">(<?= $view->e($providers[$settings->provider] ?? $settings->provider) ?>)</span></dd>
            <dt>Spent today</dt>
            <dd>$<?= $view->e(BudgetPolicy::money($usage->today)) ?> <span class="muted small">(<?= $view->e($usage->requestsToday) ?> calls)</span></dd>
            <dt>Spent this month</dt>
            <dd>$<?= $view->e(BudgetPolicy::money($usage->month)) ?> <span class="muted small">(<?= $view->e($usage->requestsMonth) ?> calls; estimated from the tokens the provider reports)</span></dd>
        </dl>
        <?php if (!$settings->isActive() && $settings->lastError !== null): ?>
            <p class="notice notice-error"><?= $view->e($settings->lastError) ?></p>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <div class="actions">
                <form method="post" action="/integrations/assistant/model" class="inline-form">
                    <?= $view->csrfField() ?>
                    <label class="visually-hidden" for="model_change">Model</label>
                    <select id="model_change" name="model" data-autosubmit>
                        <?php foreach ($models[$settings->provider] ?? [] as $id => $label): ?>
                            <option value="<?= $view->e($id) ?>"<?= $id === $settings->model ? ' selected' : '' ?>><?= $view->e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <form method="post" action="/integrations/assistant/disconnect" class="inline-form" data-confirm="Delete the <?= $view->e($name) ?> API key? The chat stops working until a new key is added.">
                    <?= $view->csrfField() ?>
                    <button type="submit" class="button button-small button-danger">Remove key</button>
                </form>
            </div>
        <?php endif; ?>
    <?php elseif (!$canManage): ?>
        <p class="muted">Not set up. A workspace owner or admin can add a Claude or OpenAI API key.</p>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <h3><?= $settings === null ? 'Set up' : 'Change provider or API key' ?></h3>
        <ol class="small">
            <li><strong>Claude:</strong> sign in to the <a href="https://console.anthropic.com/settings/keys" rel="noopener noreferrer" target="_blank">Claude Console</a>, add credit under <em>Billing</em> and create a key under <em>API keys</em>.<br><strong>GPT:</strong> sign in to the <a href="https://platform.openai.com/api-keys" rel="noopener noreferrer" target="_blank">OpenAI platform</a>, add credit under <em>Billing</em> and create a secret key under <em>API keys</em>.</li>
            <li>Choose the model (it decides the provider) and paste the matching key. It is checked with the provider, then stored encrypted and never shown again.</li>
        </ol>
        <form method="post" action="/integrations/assistant" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['api_key']) ? ' has-error' : '' ?>">
                <label for="api_key">API key</label>
                <input id="api_key" name="api_key" type="password" required maxlength="300" autocomplete="off" spellcheck="false" placeholder="sk-ant-… or sk-…"<?= $view->invalid($errors, 'api_key', 'api_key') ?>>
                <?= $view->fieldError($errors, 'api_key', 'api_key') ?>
            </div>
            <div class="field<?= isset($errors['model']) ? ' has-error' : '' ?>">
                <label for="model">Model</label>
                <select id="model" name="model"<?= $view->invalid($errors, 'model', 'model') ?>>
                    <?php foreach ($models as $group => $list): ?>
                        <optgroup label="<?= $view->e($providers[$group] ?? $group) ?>">
                            <?php foreach ($list as $id => $label): ?>
                                <option value="<?= $view->e($id) ?>"<?= $id === ($old['model'] ?? $currentModel) ? ' selected' : '' ?>><?= $view->e($label) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <?= $view->fieldError($errors, 'model', 'model') ?>
            </div>
            <div class="field<?= isset($errors['provider_workspace']) ? ' has-error' : '' ?>">
                <label for="provider_workspace">Claude workspace ID <span class="muted">(optional)</span></label>
                <input id="provider_workspace" name="provider_workspace" type="text" maxlength="64" autocomplete="off" spellcheck="false" placeholder="wrkspc_…" value="<?= $view->e(is_string($old['provider_workspace'] ?? null) ? $old['provider_workspace'] : ($settings?->providerWorkspace ?? '')) ?>"<?= $view->invalid($errors, 'provider_workspace', 'provider_workspace') ?>>
                <p class="muted small">Only needed if Claude reports that the key is “not scoped to a workspace”: such a key works in several Claude workspaces, so name the one to use. Copy its ID from Claude Console → Settings → Workspaces. Leave empty for GPT and for keys created inside one workspace.</p>
                <?= $view->fieldError($errors, 'provider_workspace', 'provider_workspace') ?>
            </div>
            <button type="submit" class="button"><?= $settings === null ? 'Check and save' : 'Save new API key' ?></button>
        </form>
    <?php endif; ?>
</section>

<?php if ($canManage): ?>
    <section class="card">
        <h2>Budgets</h2>
        <p class="muted small">A question usually takes one to four calls; with Sonnet a call costs about $0.01–0.05, depending on how much data the answer reads. When a budget is reached, the chat says so and stops until the next day or month.</p>
        <form method="post" action="/integrations/assistant/budget" class="form" novalidate>
            <?= $view->csrfField() ?>
            <?php foreach ([
                'daily_limit' => ['Daily budget (USD)', $budget->dailyLimit],
                'monthly_limit' => ['Monthly budget (USD)', $budget->monthlyLimit],
                'operation_limit' => ['Maximum per call (USD)', $budget->operationLimit],
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

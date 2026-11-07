<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Modules\Bing\BingAccount|null $account
 * @var bool $canManage
 * @var array<string, string> $errors
 */
$view->extend('layouts/app', ['title' => 'Bing Webmaster Tools']);
?>
<div class="page-header">
    <h1>Bing Webmaster Tools</h1>
    <a class="button button-ghost" href="/integrations">All integrations</a>
</div>
<?= $view->partial('partials/integration-guide', ['guide' => 'bing', 'open' => false]) ?>

<section class="card">
    <h2>Account</h2>
    <p class="muted">Clicks, impressions, queries and pages from Bing, which also powers Yahoo, DuckDuckGo, Ecosia and the web search of ChatGPT and Copilot. Data is imported twice a day and stored on this server; the API is free.</p>

    <?php if ($account !== null): ?>
        <dl class="details">
            <dt>Status</dt>
            <dd>
                <?php if ($account->isActive()): ?><span class="badge badge-ok">Connected</span><?php else: ?><span class="badge badge-error">Access refused</span><?php endif; ?>
                <?php if ($account->checkedAt !== null): ?> <span class="muted small">checked <?= $view->e($account->checkedAt) ?> UTC</span><?php endif; ?>
            </dd>
            <dt>Verified sites</dt>
            <dd>
                <?php if ($account->sites === []): ?>
                    <span class="muted">None. Add and verify your site in Bing Webmaster Tools, then press <em>Refresh sites</em>.</span>
                <?php else: ?>
                    <ul class="plain-list">
                        <?php foreach ($account->sites as $site): ?><li><?= $view->e($site) ?></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </dd>
        </dl>
        <?php if (!$account->isActive() && $account->lastError !== null): ?>
            <p class="notice notice-error"><?= $view->e($account->lastError) ?></p>
        <?php endif; ?>
        <?php if ($canManage): ?>
            <div class="actions">
                <form method="post" action="/integrations/bing/refresh" class="inline-form">
                    <?= $view->csrfField() ?>
                    <button type="submit" class="button button-small button-ghost">Refresh sites</button>
                </form>
                <form method="post" action="/integrations/bing/disconnect" class="inline-form">
                    <?= $view->csrfField() ?>
                    <button type="submit" class="button button-small button-danger">Disconnect</button>
                </form>
            </div>
        <?php endif; ?>
    <?php elseif (!$canManage): ?>
        <p class="muted">Not connected. A workspace owner or admin can connect Bing Webmaster Tools.</p>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <h3><?= $account === null ? 'Connect' : 'Replace API key' ?></h3>
        <ol class="small">
            <li>Sign in to <a href="https://www.bing.com/webmasters" rel="noopener noreferrer" target="_blank">Bing Webmaster Tools</a> and make sure your site is added and verified. Sites from Google Search Console can be imported there in one step.</li>
            <li>Open <em>Settings</em> (top right) → <em>API Access</em> → <em>API Key</em>, accept the terms and click <em>Generate API Key</em>.</li>
            <li>Paste the key below. It is checked with Bing, then stored encrypted and never shown again.</li>
        </ol>
        <form method="post" action="/integrations/bing" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['api_key']) ? ' has-error' : '' ?>">
                <label for="api_key">API key</label>
                <input id="api_key" name="api_key" type="password" required maxlength="128" autocomplete="off" spellcheck="false"<?= $view->invalid($errors, 'api_key', 'api_key') ?>>
                <?= $view->fieldError($errors, 'api_key', 'api_key') ?>
            </div>
            <button type="submit" class="button"><?= $account === null ? 'Connect Bing' : 'Save new API key' ?></button>
        </form>
    <?php endif; ?>
</section>

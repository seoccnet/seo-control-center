<?php
/**
 * MCP sign-in: the signed-in user allows an AI app (e.g. ChatGPT) to read a workspace's data (ADR-029).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Modules\Mcp\OAuth\AuthorizationRequest $authorization
 * @var list<SeoControlCenter\Domain\Workspaces\Workspace> $workspaces
 * @var string|null $current
 * @var array<string, string> $scopes scope => label
 * @var string $returnHost
 * @var string|null $error
 */
$view->extend('layouts/app', ['title' => 'Connect ' . $authorization->client->name]);
?>
<div class="page-header">
    <h1>Connect <?= $view->e($authorization->client->name) ?></h1>
</div>

<section class="card oauth-card">
    <?php if ($workspaces === []): ?>
        <p class="notice notice-error">Only owners and admins of a workspace can connect an AI app. Ask an owner of your workspace to connect it.</p>
        <form method="post" action="/oauth/authorize" class="actions">
            <?= $view->csrfField() ?>
            <?php foreach ($authorization->parameters() as $name => $value): ?>
                <input type="hidden" name="<?= $view->e($name) ?>" value="<?= $view->e($value) ?>">
            <?php endforeach; ?>
            <button type="submit" name="decision" value="deny" class="button button-ghost">Back to <?= $view->e($returnHost) ?></button>
        </form>
    <?php else: ?>
        <p><strong><?= $view->e($authorization->client->name) ?></strong> wants to <strong>read</strong> the SEO data of a workspace. It can never change data, start paid lookups or see passwords and keys.</p>
        <?php if ($error !== null): ?>
            <p class="notice notice-error" role="alert"><?= $view->e($error) ?></p>
        <?php endif; ?>
        <form method="post" action="/oauth/authorize" class="form">
            <?= $view->csrfField() ?>
            <?php foreach ($authorization->parameters() as $name => $value): ?>
                <input type="hidden" name="<?= $view->e($name) ?>" value="<?= $view->e($value) ?>">
            <?php endforeach; ?>
            <div class="field">
                <label for="workspace">Workspace</label>
                <select id="workspace" name="workspace">
                    <?php foreach ($workspaces as $workspace): ?>
                        <option value="<?= $view->e($workspace->publicId) ?>"<?= $workspace->publicId === $current ? ' selected' : '' ?>><?= $view->e($workspace->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <fieldset class="field">
                <legend>Data it may read</legend>
                <?php foreach ($scopes as $value => $label): ?>
                    <?php if (!str_ends_with($value, ':write')): ?>
                        <label><input type="checkbox" name="scopes[]" value="<?= $view->e($value) ?>" checked> <?= $view->e($label) ?></label>
                    <?php endif; ?>
                <?php endforeach; ?>
            </fieldset>
            <?php if (array_filter(array_keys($scopes), static fn(string $v): bool => str_ends_with($v, ':write')) !== []): ?>
                <fieldset class="field">
                    <legend>Changes it may make (optional)</legend>
                    <p class="muted small">Not ticked by default. Changes use your own permissions, are logged and show the app's name. It can never start paid lookups or delete anything.</p>
                    <?php foreach ($scopes as $value => $label): ?>
                        <?php if (str_ends_with($value, ':write')): ?>
                            <label><input type="checkbox" name="scopes[]" value="<?= $view->e($value) ?>"> <?= $view->e($label) ?></label>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <p class="muted small">This creates an access token for <?= $view->e($authorization->client->name) ?>, valid for all projects you can see. It appears under Integrations → AI access, where you can revoke it at any time. You then return to <?= $view->e($returnHost) ?>.</p>
            <div class="actions">
                <button type="submit" name="decision" value="allow" class="button">Allow</button>
                <button type="submit" name="decision" value="deny" class="button button-ghost">Cancel</button>
            </div>
        </form>
    <?php endif; ?>
</section>

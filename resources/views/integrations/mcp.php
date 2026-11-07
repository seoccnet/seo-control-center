<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<SeoControlCenter\Domain\Mcp\McpToken> $tokens
 * @var list<SeoControlCenter\Domain\Projects\Project> $projects
 * @var list<SeoControlCenter\Domain\Mcp\McpScope> $scopes
 * @var list<int> $expiryDays
 * @var string $endpoint
 * @var string|null $newToken
 * @var array<string, string> $errors
 * @var array<string, mixed> $old
 */
$view->extend('layouts/app', ['title' => 'AI access (MCP)']);
// Read scopes start ticked; write scopes (ADR-036) only when the user ticks them.
$oldScopes = is_array($old['scopes'] ?? null) ? $old['scopes'] : array_map(static fn($s): string => $s->value, array_filter($scopes, static fn($s): bool => !$s->isWrite()));
$oldProjects = is_array($old['projects'] ?? null) ? $old['projects'] : [];
$restricted = ($old['project_access'] ?? 'all') !== 'all';
$now = gmdate('Y-m-d H:i:s');
$projectNames = [];
foreach ($projects as $p) {
    $projectNames[$p->id] = $p->name;
}
?>
<div class="page-header">
    <h1>AI access (MCP)</h1>
    <a class="button button-ghost" href="/integrations">All integrations</a>
</div>
<?= $view->partial('partials/integration-guide', ['guide' => 'mcp', 'open' => false]) ?>

<?php if ($newToken !== null): ?>
    <section class="card">
        <h2>Your new token</h2>
        <p class="notice notice-success">Copy it now. It is shown only once and cannot be recovered; create a new one if it is lost.</p>
        <p><code class="token-value"><?= $view->e($newToken) ?></code></p>
        <p class="muted small">Claude Code:</p>
        <p><code class="token-value">claude mcp add --transport http seo-control-center <?= $view->e($endpoint) ?> --header "Authorization: Bearer <?= $view->e($newToken) ?>"</code></p>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Connect an AI client</h2>
    <p class="muted">AI assistants that support the Model Context Protocol (for example Claude) can read this workspace's SEO data through the endpoint below. Access is <strong>read-only</strong>: clients can never change data, start paid DataForSEO requests, or see credentials. Every tool call is recorded in the audit log.</p>
    <dl class="details">
        <dt>Endpoint</dt><dd><code><?= $view->e($endpoint) ?></code> (Streamable HTTP)</dd>
        <dt>Authentication</dt><dd>HTTP header <code>Authorization: Bearer &lt;token&gt;</code></dd>
        <dt>Rate limit</dt><dd>60 requests per minute per token</dd>
    </dl>
</section>

<section class="card">
    <h2>Connect ChatGPT</h2>
    <p class="muted">ChatGPT connects with a sign-in instead of a token (paid ChatGPT plans with developer mode).</p>
    <ol class="small">
        <li>In ChatGPT open <em>Settings → Apps &amp; Connectors → Advanced settings</em> and turn on <em>Developer mode</em>.</li>
        <li>Choose <em>Create</em> (or <em>Add custom connector</em>), enter a name such as "SEO Control Center" and the server URL <code><?= $view->e($endpoint) ?></code>, and choose <em>OAuth</em> as the authentication.</li>
        <li>ChatGPT opens this site: sign in, choose the workspace and the data it may read, and click <em>Allow</em>.</li>
    </ol>
    <p class="muted small">ChatGPT then gets its own token, listed below as "ChatGPT (sign-in)". Revoke it there to disconnect. Owners and admins can connect apps. Claude.ai custom connectors use the same sign-in.</p>
</section>

<section class="card">
    <h2>Create a token</h2>
    <form method="post" action="/integrations/mcp" class="form" novalidate>
        <?= $view->csrfField() ?>
        <div class="field<?= isset($errors['name']) ? ' has-error' : '' ?>">
            <label for="name">Name</label>
            <input id="name" name="name" type="text" maxlength="120" required placeholder="Claude Desktop" value="<?= $view->e(is_string($old['name'] ?? null) ? $old['name'] : '') ?>"<?= $view->invalid($errors, 'name', 'name') ?>>
            <?= $view->fieldError($errors, 'name', 'name') ?>
        </div>
        <fieldset class="field<?= isset($errors['scopes']) ? ' has-error' : '' ?>"<?= isset($errors['scopes']) ? ' aria-describedby="scopes-error"' : '' ?>>
            <legend>Data the client may read</legend>
            <?php foreach ($scopes as $scope): ?>
                <?php if (!$scope->isWrite()): ?>
                    <label><input type="checkbox" name="scopes[]" value="<?= $view->e($scope->value) ?>"<?= in_array($scope->value, $oldScopes, true) ? ' checked' : '' ?>> <?= $view->e($scope->label()) ?></label>
                <?php endif; ?>
            <?php endforeach; ?>
            <?= $view->fieldError($errors, 'scopes', 'scopes') ?>
        </fieldset>
        <fieldset class="field">
            <legend>Changes the client may make</legend>
            <p class="muted small">Optional. Changes go through your own permissions, are logged in the audit log and show the token name. The client can never start paid lookups or delete anything.</p>
            <?php foreach ($scopes as $scope): ?>
                <?php if ($scope->isWrite()): ?>
                    <label><input type="checkbox" name="scopes[]" value="<?= $view->e($scope->value) ?>"<?= in_array($scope->value, $oldScopes, true) ? ' checked' : '' ?>> <?= $view->e($scope->label()) ?></label>
                <?php endif; ?>
            <?php endforeach; ?>
        </fieldset>
        <fieldset class="field<?= isset($errors['projects']) ? ' has-error' : '' ?>"<?= isset($errors['projects']) ? ' aria-describedby="projects-error"' : '' ?>>
            <legend>Projects</legend>
            <label><input type="radio" name="project_access" value="all"<?= !$restricted ? ' checked' : '' ?>> All projects you can see (including future ones)</label>
            <label><input type="radio" name="project_access" value="selected"<?= $restricted ? ' checked' : '' ?>> Only these projects:</label>
            <?php foreach ($projects as $project): ?>
                <label class="indent"><input type="checkbox" name="projects[]" value="<?= $view->e($project->publicId) ?>"<?= in_array($project->publicId, $oldProjects, true) ? ' checked' : '' ?>> <?= $view->e($project->name) ?> <span class="muted small"><?= $view->e($project->primaryDomain) ?></span></label>
            <?php endforeach; ?>
            <?= $view->fieldError($errors, 'projects', 'projects') ?>
        </fieldset>
        <div class="field<?= isset($errors['expires']) ? ' has-error' : '' ?>">
            <label for="expires">Expires</label>
            <select id="expires" name="expires"<?= $view->invalid($errors, 'expires', 'expires') ?>>
                <?php foreach ($expiryDays as $days): ?>
                    <option value="<?= $view->e($days) ?>"<?= (string) $days === ($old['expires'] ?? '90') ? ' selected' : '' ?>><?= $days === 0 ? 'Never' : 'In ' . $view->e($days) . ' days' ?></option>
                <?php endforeach; ?>
            </select>
            <?= $view->fieldError($errors, 'expires', 'expires') ?>
        </div>
        <p class="muted small">The token reads with your access: it stops working if you leave this workspace or your account is deactivated.</p>
        <button type="submit" class="button">Create token</button>
    </form>
</section>

<section class="card">
    <h2>Tokens</h2>
    <?php if ($tokens === []): ?>
        <p class="muted">No tokens yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Name</th><th scope="col">Token</th><th scope="col">Access</th><th scope="col">Status</th><th scope="col">Last used (UTC)</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody>
                    <?php foreach ($tokens as $token): ?>
                        <?php $active = $token->isUsable(new DateTimeImmutable($now, new DateTimeZone('UTC'))); ?>
                        <tr>
                            <td><?= $view->e($token->name) ?></td>
                            <td><code><?= $view->e($token->prefix) ?>…</code></td>
                            <td class="small">
                                <?= $view->e(implode(', ', array_map(static fn($s): string => $s->label(), $token->scopes))) ?><br>
                                <span class="muted"><?= $token->projectIds === null ? 'all projects' : $view->e(implode(', ', array_map(static fn(int $id): string => $projectNames[$id] ?? 'hidden project', $token->projectIds))) ?></span>
                            </td>
                            <td>
                                <?php if ($token->revokedAt !== null): ?><span class="badge badge-error">Revoked</span>
                                <?php elseif (!$active): ?><span class="badge badge-warning">Expired</span>
                                <?php else: ?><span class="badge badge-ok">Active</span><?php if ($token->expiresAt !== null): ?> <span class="muted small">until <?= $view->e(substr($token->expiresAt, 0, 10)) ?></span><?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="muted small"><?= $view->e($token->lastUsedAt ?? 'never') ?></td>
                            <td>
                                <?php if ($token->revokedAt === null): ?>
                                    <form method="post" action="/integrations/mcp/<?= $view->e($token->publicId) ?>/revoke" class="inline-form">
                                        <?= $view->csrfField() ?>
                                        <button type="submit" class="button button-small button-danger">Revoke</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

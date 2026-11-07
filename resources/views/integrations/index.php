<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var list<array{connection: SeoControlCenter\Domain\Integrations\Connection, properties: list<SeoControlCenter\Domain\SearchConsole\SearchConsoleProperty>}> $connections
 * @var bool $canManage
 * @var bool $configured
 */
$view->extend('layouts/app', ['title' => 'Integrations']);
$permissionLabels = [
    'siteOwner' => 'Owner',
    'siteFullUser' => 'Full user',
    'siteRestrictedUser' => 'Restricted user',
    'siteUnverifiedUser' => 'Unverified — no data access',
];
?>
<div class="page-header">
    <h1>Integrations</h1>
</div>

<section class="card">
    <h2>Google Search Console</h2>
    <p class="muted">Connect a Google account with access to your Search Console properties. <?= $view->e(isset($brand) ? $brand()->name($appName ?? '') : 'SEO Control Center') ?> only requests read-only access; your data is stored on this server.</p>

    <?php if (!$configured): ?>
        <p class="notice notice-error">Google is not configured yet. A system administrator must add the OAuth client under <em>System → Google app</em>.</p>
    <?php elseif ($canManage): ?>
        <form method="post" action="/integrations/google/connect">
            <?= $view->csrfField() ?>
            <button type="submit" class="button">Connect a Google account</button>
        </form>
    <?php endif; ?>

    <?php foreach ($connections as ['connection' => $connection, 'properties' => $properties]): ?>
        <div class="connection">
            <h3>
                <?= $view->e($connection->accountEmail) ?>
                <?php if ($connection->status === 'active'): ?>
                    <span class="badge badge-ok">Connected</span>
                <?php else: ?>
                    <span class="badge badge-error">Needs reconnection</span>
                <?php endif; ?>
            </h3>
            <?php if ($connection->lastError !== null && $connection->status !== 'active'): ?>
                <p class="muted small"><?= $view->e($connection->lastError) ?></p>
            <?php endif; ?>

            <?php if ($properties === []): ?>
                <p class="muted">No Search Console properties found for this account.</p>
            <?php else: ?>
                <div class="table-scroll"><table class="table table-dense">
                    <thead><tr><th scope="col">Property</th><th scope="col">Access</th></tr></thead>
                    <tbody>
                        <?php foreach ($properties as $property): ?>
                            <tr>
                                <td><?= $view->e($property->label()) ?></td>
                                <td><?= $view->e($permissionLabels[$property->permissionLevel] ?? $property->permissionLevel) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>

            <?php if ($canManage): ?>
                <div class="actions">
                    <form method="post" action="/integrations/<?= $view->e($connection->publicId) ?>/refresh" class="inline-form">
                        <?= $view->csrfField() ?>
                        <button type="submit" class="button button-small button-ghost">Refresh properties</button>
                    </form>
                    <form method="post" action="/integrations/<?= $view->e($connection->publicId) ?>/disconnect" class="inline-form">
                        <?= $view->csrfField() ?>
                        <button type="submit" class="button button-small button-danger">Disconnect</button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>

<div class="integration-grid">
<section class="card">
    <h2>Bing Webmaster Tools</h2>
    <p class="muted">Search performance from your verified Bing websites: clicks, impressions, queries and pages. Connect with a Bing Webmaster Tools API key.</p>
    <p><a class="button" href="/integrations/bing">Bing settings</a></p>
</section>

<section class="card">
    <h2>DataForSEO</h2>
    <p class="muted">Keyword metrics and search results from your own DataForSEO account, with daily and monthly budgets.</p>
    <p><a class="button" href="/integrations/dataforseo">DataForSEO settings</a></p>
</section>

<section class="card">
    <h2>AI access (MCP)</h2>
    <p class="muted">Connect an AI client to selected projects with explicit scopes, revocable tokens or an approved OAuth connection.</p>
    <p><a class="button" href="/integrations/mcp">Manage AI access</a></p>
</section>

<section class="card">
    <h2>AI assistant</h2>
    <p class="muted">The <em>Ask AI</em> chat on every page: Claude or GPT answers questions about your data and helps work through the opportunities, with your own API key and budget.</p>
    <p><a class="button" href="/integrations/assistant">AI assistant settings</a></p>
</section>
<?php if ($canManage): ?>
<section class="card"><h2>Core Web Vitals</h2><p class="muted">Mobile and desktop visitor measurements from Google's Chrome UX Report, with separate results for loading, responsiveness and visual stability.</p><p><a class="button" href="/integrations/web-vitals">Core Web Vitals settings</a></p></section>
<section class="card"><h2>Google Analytics 4</h2><p class="muted">Organic landing-page sessions, conversions and revenue from a linked GA4 property using read-only access.</p><p><a class="button" href="/integrations/ga4">Google Analytics settings</a></p></section>
<?php endif; ?>
</div>
<section class="integration-tutorials" aria-labelledby="integration-tutorials-title">
<div class="section-heading"><h2 id="integration-tutorials-title">Connection guides</h2></div>
<p class="muted">Follow the steps below, with screenshots from Google and links to each setup screen. Other providers have a guide on their settings page.</p>
<?= $view->partial('partials/integration-guide', ['guide' => 'google']) ?>
<?= $view->partial('partials/integration-guide', ['guide' => 'ga4']) ?>
<?= $view->partial('partials/integration-guide', ['guide' => 'crux']) ?>
</section>

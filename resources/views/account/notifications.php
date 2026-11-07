<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Modules\Alerts\NotificationSettings $settings
 * @var list<SeoControlCenter\Modules\Alerts\AlertType> $types
 * @var list<SeoControlCenter\Domain\Projects\Project> $projects
 * @var list<SeoControlCenter\Modules\Alerts\Alert> $alerts
 * @var array<int, string> $projectNames
 * @var bool $mailConfigured
 * @var bool $aiAvailable
 * @var string $workspaceName
 * @var array<int, SeoControlCenter\Modules\Alerts\AlertThresholds> $projectThresholds
 * @var list<int> $editableProjects
 */
$view->extend('layouts/app', ['title' => 'Notifications']);
?>
<div class="page-header">
    <h1>Notifications</h1>
    <a class="button button-ghost" href="/account">Your account</a>
</div>

<?php if (!$mailConfigured): ?>
    <p class="notice notice-warning">E-mail is not set up, so email alerts and digests cannot be sent. Alerts still appear below; enabled team webhooks work independently. An administrator can configure mail under System.</p>
<?php endif; ?>

<form method="post" action="/account/notifications" class="form notifications-form" novalidate>
    <?= $view->csrfField() ?>
    <section class="card">
        <h2>Alerts</h2>
        <p class="muted">An e-mail as soon as something needs your attention. Checked every hour, each event once.</p>
        <label><input type="checkbox" name="alerts" value="1"<?= $settings->alerts ? ' checked' : '' ?>> E-mail me alerts</label>
        <fieldset class="notification-types">
            <legend class="small muted">Which alerts</legend>
            <?php foreach ($types as $type): ?>
                <label class="indent">
                    <input type="checkbox" name="types[]" value="<?= $view->e($type->value) ?>"<?= in_array($type, $settings->mutedTypes, true) ? '' : ' checked' ?>>
                    <?= $view->e($type->label()) ?> <span class="muted small"><?= $view->e($type->description()) ?></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
    </section>

    <section class="card">
        <h2>Weekly digest</h2>
        <p class="muted">Every Monday morning (UTC): clicks, rankings, site health, the top opportunities and the week's alerts of each project, in one e-mail per workspace.</p>
        <label><input type="checkbox" name="digest" value="1"<?= $settings->digest ? ' checked' : '' ?>> E-mail me the weekly digest</label>
        <label class="indent">
            <input type="checkbox" name="digest_ai" value="1"<?= $settings->digestAi ? ' checked' : '' ?>>
            Add a short AI summary
            <span class="muted small"><?= $aiAvailable ? 'Uses the workspace AI assistant and counts against its monthly budget.' : 'Needs the AI assistant to be set up in this workspace.' ?></span>
        </label>
    </section>

    <?php if ($projects !== []): ?>
        <section class="card">
            <h2>Projects in <?= $view->e($workspaceName) ?></h2>
            <p class="muted">Unchecked projects send no alerts to you. They still appear in the digest.</p>
            <?php foreach ($projects as $project): ?>
                <label>
                    <input type="checkbox" name="projects[]" value="<?= $view->e($project->publicId) ?>"<?= in_array($project->id, $settings->mutedProjects, true) ? '' : ' checked' ?>>
                    <?= $view->e($project->name) ?> <span class="muted small"><?= $view->e($project->primaryDomain) ?></span>
                </label>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <div class="actions"><button type="submit" class="button">Save notification settings</button></div>
</form>

<?php if ($editableProjects !== []): ?>
<section class="card" id="project-alert-thresholds">
    <h2>Project alert sensitivity</h2>
    <p class="muted">These shared settings apply to everyone receiving a project's alerts. Compare the latest seven days with the previous seven. Higher drop percentages and minimum clicks reduce traffic noise.</p>
    <?php foreach ($projects as $project): if (!in_array($project->id, $editableProjects, true)) { continue; } $threshold = $projectThresholds[$project->id]; $prefix = 'threshold-' . $project->publicId; ?>
    <details class="help-details">
        <summary><?= $view->e($project->name) ?> · <?= $view->e($project->primaryDomain) ?></summary>
        <form class="form" method="post" action="/account/notifications/projects/<?= $view->e($project->publicId) ?>">
            <?= $view->csrfField() ?>
            <div class="form-grid">
                <div class="field"><label for="<?= $prefix ?>-traffic">Traffic drop (%)</label><input id="<?= $prefix ?>-traffic" type="number" name="traffic_drop" min="1" max="100" value="<?= $threshold->trafficDrop ?>" required><p class="muted small">Alert when clicks fall by at least this percentage.</p></div>
                <div class="field"><label for="<?= $prefix ?>-clicks">Minimum previous clicks</label><input id="<?= $prefix ?>-clicks" type="number" name="minimum_clicks" min="1" max="1000000" value="<?= $threshold->minimumClicks ?>" required><p class="muted small">Skip comparisons with fewer clicks in the previous week.</p></div>
                <div class="field"><label for="<?= $prefix ?>-rank">Ranking cutoff</label><input id="<?= $prefix ?>-rank" type="number" name="ranking_cutoff" min="1" max="100" value="<?= $threshold->rankingCutoff ?>" required><p class="muted small">Alert when a keyword falls out of this top-position group.</p></div>
                <div class="field"><label for="<?= $prefix ?>-links">Minimum lost-link domain rank</label><input id="<?= $prefix ?>-links" type="number" name="lost_domain_rank" min="0" max="100" value="<?= $threshold->lostDomainRank ?>" required><p class="muted small">Alert only for lost domains at or above this rank. Unknown ranks are excluded.</p></div>
            </div>
            <button class="button" type="submit">Save project thresholds</button>
        </form>
    </details>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($webhookProjects !== []): ?>
<section class="card" id="alert-delivery">
    <h2>Team alert delivery</h2>
    <p class="muted">Send future project alerts to a Slack channel or your own HTTPS endpoint. This shared destination receives project alerts independently of personal email preferences. Budget alerts stay in email.</p>
    <details class="help-details"><summary>How to connect a destination</summary>
        <ol><li>For Slack, create an incoming webhook for the channel in your Slack app settings. Copy its URL and choose Slack below.</li><li>For another service, create a public HTTPS endpoint accepting JSON POST requests. Choose JSON and optionally enter its bearer token.</li><li>Enable delivery and save. The next new alert verifies the connection; its result appears below. Existing alerts are not replayed.</li></ol>
        <p class="muted small">JSON includes event, id, type, title, lines, url and created_at. Use the event ID to reject duplicates. URLs and tokens are encrypted and never displayed again. Redirects and private addresses are blocked. Each alert has one delivery attempt; an interrupted or unconfirmed attempt is not retried automatically.</p>
    </details>
    <?php foreach ($projects as $project): if (!in_array($project->id, $webhookProjects, true)) { continue; } $hook = $projectWebhooks[$project->id]; $prefix = 'webhook-' . $project->publicId; ?>
    <details class="help-details"><summary><?= $view->e($project->name) ?> · <?= $hook?->enabled ? 'Enabled' : 'Not enabled' ?></summary>
        <?php if ($hook !== null): ?><p class="muted small">Configured host: <?= $view->e($hook->host()) ?>. Leave secrets blank to keep them.</p><?php endif; ?>
        <form class="form" method="post" action="/account/notifications/projects/<?= $view->e($project->publicId) ?>/webhook">
            <?= $view->csrfField() ?>
            <div class="field"><label for="<?= $prefix ?>-format">Payload format</label><select id="<?= $prefix ?>-format" name="webhook_format"><option value="json"<?= $hook?->format === 'json' ? ' selected' : '' ?>>JSON endpoint</option><option value="slack"<?= $hook?->format === 'slack' ? ' selected' : '' ?>>Slack incoming webhook</option></select></div>
            <div class="field"><label for="<?= $prefix ?>-url">HTTPS webhook URL</label><input id="<?= $prefix ?>-url" type="password" name="webhook_url" maxlength="2048" autocomplete="new-password"<?= $hook === null ? ' required' : '' ?>><p class="muted small">Treat this URL as a secret. Do not include account credentials or a custom port.</p></div>
            <div class="field"><label for="<?= $prefix ?>-token">Bearer token (JSON only, optional)</label><input id="<?= $prefix ?>-token" type="password" name="webhook_token" maxlength="4096" autocomplete="new-password"></div>
            <label><input type="checkbox" name="clear_webhook_token" value="1"> Remove the stored bearer token</label>
            <label><input type="checkbox" name="webhook_enabled" value="1"<?= $hook?->enabled ? ' checked' : '' ?>> Enable delivery of future project alerts</label>
            <button class="button" type="submit">Save alert delivery</button>
        </form>
        <?php if ($hook !== null): ?><form method="post" action="/account/notifications/projects/<?= $view->e($project->publicId) ?>/webhook"><?= $view->csrfField() ?><input type="hidden" name="remove_webhook" value="1"><button class="button button-secondary" type="submit">Remove destination</button></form><?php endif; ?>
        <h3>Recent delivery attempts</h3>
        <?php if ($webhookHistory[$project->id] === []): ?><p class="muted small">No delivery attempts yet.</p><?php else: ?><ul class="alert-list"><?php foreach ($webhookHistory[$project->id] as $delivery): ?><li><strong><?= $view->e($delivery['status']) ?></strong> · <?= $view->e($delivery['created_at']) ?> UTC<p class="muted small"><?= $view->e($delivery['message'] ?? 'Delivery started; confirmation was not recorded.') ?><?= $delivery['http_status'] !== null ? ' HTTP ' . $delivery['http_status'] . '.' : '' ?></p></li><?php endforeach; ?></ul><?php endif; ?>
    </details>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="card">
    <h2>Recent alerts</h2>
    <?php if ($alerts === []): ?>
        <p class="muted">No alerts in the last 30 days. That is good news.</p>
    <?php else: ?>
        <ul class="alert-list">
            <?php foreach ($alerts as $alert): ?>
                <li class="alert-item">
                    <div class="alert-item-head">
                        <a href="<?= $view->e($alert->url) ?>"><?= $view->e($alert->title) ?></a>
                        <span class="badge badge-warning"><?= $view->e($alert->type->label()) ?></span>
                    </div>
                    <div class="muted small">
                        <?= $alert->projectId !== null && isset($projectNames[$alert->projectId]) ? $view->e($projectNames[$alert->projectId]) . ' · ' : '' ?><?= $view->e($alert->createdAt) ?> UTC
                    </div>
                    <?php if ($alert->lines !== []): ?>
                        <ul class="small">
                            <?php foreach (array_slice($alert->lines, 0, 5) as $line): ?>
                                <li><?= $view->e($line) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

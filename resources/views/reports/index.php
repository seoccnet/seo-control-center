<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Modules\Reports\ReportSchedule|null $schedule
 * @var list<array{period: string, kind: string, recipients: int, failed: int, error: ?string, created_at: string}> $deliveries
 * @var bool $mailConfigured
 * @var bool $canManage
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
$view->extend('layouts/app', ['title' => 'Reports · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [null, 'Reports']]]);
$base = '/projects/' . $project->publicId . '/reports';
$frequency = $old['frequency'] ?? ($schedule->frequency ?? 'weekly');
$recipients = $old['recipients'] ?? implode("\n", $schedule->recipients ?? []);
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'reports']) ?>
<div class="manage-workspace manage-reports">
<header class="workspace-intro"><p class="portfolio-eyebrow">Manage · Reports</p><h2>Turn your results into client reports</h2><p class="muted">Preview results, choose report content and manage delivery.</p></header>

<section class="card">
    <div class="card-header">
        <h2>View a report</h2>
    </div>
    <p class="muted">Google + Bing search figures, top pages and queries, rankings and open opportunities for a week or a month, compared with the period before. Print it or save it as a PDF from the report page.</p>
    <div class="actions">
        <a class="button" href="<?= $view->e($base) ?>/view?frequency=weekly"><?= $view->icon('arrow-right') ?>Last week</a>
        <a class="button button-ghost" href="<?= $view->e($base) ?>/view?frequency=monthly">Last month</a>
    </div>
</section>

<section class="card"><h2>Report content</h2><p class="muted">Choose the sections this client needs. The same selection is used in the printable report, HTML email and plain-text email. Stored project data stays available in the dashboard.</p>
<?php if ($canManage): ?><form class="form" method="post" action="<?= $view->e($base . '/sections') ?>"><?= $view->csrfField() ?><fieldset class="report-section-options"><legend>Included sections</legend><?php foreach (SeoControlCenter\Modules\Reports\ReportSections::LABELS as $key => $label): ?><label><input type="checkbox" name="sections[]" value="<?= $view->e($key) ?>"<?= $sections->has($key) ? ' checked' : '' ?>> <?= $view->e($label) ?></label><?php endforeach; ?></fieldset><?php foreach ($errors as $error): ?><p class="field-error" role="alert"><?= $view->e($error) ?></p><?php endforeach; ?><button class="button button-small" type="submit">Save report sections</button></form><?php else: ?><p><?= $view->e(implode(', ', array_map(static fn(string $key): string => SeoControlCenter\Modules\Reports\ReportSections::LABELS[$key], $sections->enabled))) ?></p><?php endif; ?>
</section>
<?php if ($canBrand): ?><section class="card"><h2>Workspace report branding</h2><p class="muted">Shared by every project in this workspace. Give each client workspace its own brand. Blank fields inherit installation branding; this affects reports, while application navigation keeps its installation identity.</p>
<form class="form" method="post" action="<?= $view->e($base . '/branding') ?>"><?= $view->csrfField() ?>
<label for="report-brand-name">Report brand name</label><input id="report-brand-name" name="product_name" maxlength="60" value="<?= $view->e($reportBranding->productName ?? '') ?>">
<label for="report-brand-color">Accent colour</label><input id="report-brand-color" name="accent_color" placeholder="#2f5fd0" maxlength="7" value="<?= $view->e($reportBranding->accentColor ?? '') ?>">
<label for="report-brand-footer">Report footer</label><textarea id="report-brand-footer" name="report_footer" maxlength="300" rows="3"><?= $view->e($reportBranding->reportFooter ?? '') ?></textarea>
<button class="button button-small" type="submit">Save workspace branding</button></form>
<?php if ($hasWorkspaceLogo): ?><p><img class="brand-logo" src="<?= $view->e($base . '/logo') ?>" alt="Current workspace report logo"></p><?php endif; ?>
<form class="form" enctype="multipart/form-data" method="post" action="<?= $view->e($base . '/logo') ?>"><?= $view->csrfField() ?><label for="report-logo">Workspace report logo</label><input id="report-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp" required><p class="muted small">PNG, JPEG or WebP, up to 512 KB, 16–2,000 pixels per side. Shown in printable reports. Emails use the brand name and accent without loading images.</p><button class="button button-small" type="submit">Upload workspace logo</button></form>
<form method="post" action="<?= $view->e($base . '/logo') ?>"><?= $view->csrfField() ?><input type="hidden" name="remove_logo" value="1"><button class="button button-small button-ghost" type="submit">Remove workspace report logo</button></form>
</section><?php endif; ?>



<section class="card">
    <h2>E-mail schedule</h2>
    <?php if (!$mailConfigured): ?>
        <p class="notice notice-error">E-mail is not set up yet, so no reports are sent. A system administrator can set it up under System → E-mail.</p>
    <?php endif; ?>
    <p class="muted">Weekly reports cover Monday to Sunday and go out on Wednesday at 07:00; monthly reports cover the calendar month and go out on the 3rd at 07:00 (<?= $view->e($project->timezone) ?>). The wait lets Search Console and Bing finish reporting the last days. The e-mail shows the key figures as a formatted report (with a plain-text version) and links to the full report.</p>
    <?php if ($schedule !== null): ?>
        <p class="small">
            <?php if ($schedule->enabled): ?><span class="badge badge-ok">Active</span><?php else: ?><span class="badge badge-warning">Paused</span><?php endif; ?>
            <?= $view->e(ucfirst($schedule->frequency)) ?> to <?= $view->e(count($schedule->recipients)) ?> recipient<?= count($schedule->recipients) === 1 ? '' : 's' ?>
            <?php if ($schedule->lastSentAt !== null): ?> · last sent <?= $view->e($schedule->lastSentAt) ?> UTC<?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form method="post" action="<?= $view->e($base) ?>" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['frequency']) ? ' has-error' : '' ?>">
                <label for="frequency">Frequency</label>
                <select id="frequency" name="frequency">
                    <option value="weekly"<?= $frequency === 'weekly' ? ' selected' : '' ?>>Weekly</option>
                    <option value="monthly"<?= $frequency === 'monthly' ? ' selected' : '' ?>>Monthly</option>
                </select>
                <?= $view->fieldError($errors, 'frequency', 'frequency') ?>
            </div>
            <div class="field<?= isset($errors['recipients']) ? ' has-error' : '' ?>">
                <label for="recipients">Recipients (up to 10, one per line)</label>
                <textarea id="recipients" name="recipients" rows="4" spellcheck="false"<?= $view->invalid($errors, 'recipients', 'recipients') ?>><?= $view->e($recipients) ?></textarea>
                <?= $view->fieldError($errors, 'recipients', 'recipients') ?>
                <p class="small muted">Recipients do not need an account; clients get the e-mail, and the linked full report needs a sign-in.</p>
            </div>
            <label><input type="checkbox" name="enabled" value="1"<?= ($schedule === null || $schedule->enabled) ? ' checked' : '' ?>> Send reports</label>
            <div class="actions">
                <button type="submit" class="button">Save schedule</button>
            </div>
        </form>
        <?php if ($mailConfigured): ?>
            <form method="post" action="<?= $view->e($base) ?>/test" class="inline-form">
                <?= $view->csrfField() ?>
                <input type="hidden" name="frequency" value="<?= $view->e($frequency) ?>">
                <button type="submit" class="button button-ghost button-small">Send a test report to me</button>
            </form>
        <?php endif; ?>
    <?php elseif ($schedule === null): ?>
        <p class="muted">No e-mail schedule. Project editors can set one up.</p>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Sent reports</h2>
    <?php if ($deliveries === []): ?>
        <p class="muted">No report sent yet.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Sent (UTC)</th><th scope="col">Period</th><th scope="col">Kind</th><th scope="col">Result</th></tr></thead>
                <tbody>
                    <?php foreach ($deliveries as $row): ?>
                        <?php [$from, $to] = array_pad(explode('..', $row['period'], 2), 2, ''); ?>
                        <tr>
                            <td><?= $view->e($row['created_at']) ?></td>
                            <td><?= $view->e($from) ?> – <?= $view->e($to) ?></td>
                            <td><?= $row['kind'] === 'test' ? 'Test' : 'Scheduled' ?></td>
                            <td>
                                <?php if ($row['failed'] === 0): ?>
                                    <span class="badge badge-ok">Sent</span> to <?= $view->e($row['recipients']) ?>
                                <?php else: ?>
                                    <span class="badge badge-error"><?= $view->e($row['failed']) ?> of <?= $view->e($row['recipients']) ?> failed</span> <span class="small"><?= $view->e($row['error'] ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

</div>



<?php

declare(strict_types=1);

/**
 * Business measurement on the Analytics page (ADR-042): which GA4 events count
 * as success, whether GA4 recorded them, sessions next to clicks, the limits
 * of what SEOcc can see, and the owner's own test-conversion check.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array<string, mixed> $measurement
 * @var bool $canEditProject
 * @var bool $canManage
 */

use SeoControlCenter\Domain\Analytics\BusinessMeasurement;

$base = '/projects/' . $project->publicId . '/analytics/events';
$assessment = $measurement['assessment'];
$badge = static fn(string $state): string => match ($state) {
    BusinessMeasurement::OBSERVED => 'badge badge-ok',
    BusinessMeasurement::NOT_OBSERVED => 'badge badge-warning',
    BusinessMeasurement::FAILED => 'badge badge-error',
    BusinessMeasurement::NOT_CONFIGURED => 'badge badge-info',
    default => 'badge',
};
$count = static fn(?int $value): string => $value === null ? '—' : number_format($value);
$range = $assessment['range'];
$ga4 = $measurement['ga4'];
$search = $measurement['search_console'];
$events = $assessment['events'];
?>
<section class="card business-measurement" id="business-measurement" aria-labelledby="business-measurement-title">
    <div class="card-header"><h2 id="business-measurement-title">Business measurement</h2><span class="<?= $badge($assessment['state']) ?>"><?= $view->e($assessment['label']) ?></span></div>
    <p><?= $view->e($assessment['summary']) ?></p>
    <p class="muted small">Period: <span class="mono"><?= $view->e($range['from']) ?> – <?= $view->e($range['to']) ?></span> (<?= (int) $range['days'] ?> GA4 days<?= $ga4['timezone'] !== null ? ', ' . $view->e($ga4['timezone']) : '' ?>). Project time zone: <?= $view->e($measurement['project_timezone']) ?>.</p>
    <?php foreach ($assessment['caveats'] as $caveat): ?><p class="notice" role="status"><?= $view->e($caveat) ?></p><?php endforeach; ?>

    <?php if ($events !== []): ?>
    <div class="table-scroll"><table class="table table-dense business-events" data-no-table-controls>
        <thead><tr><th scope="col">Business event</th><th scope="col">GA4 event</th><th scope="col">State</th><th scope="col" class="num">Site-wide</th><th scope="col" class="num">Organic Search</th><th scope="col">What it means</th><?php if ($canEditProject): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><strong><?= $view->e($event['label']) ?></strong><br><span class="muted small"><?= $view->e($event['kind_label']) ?></span></td>
                <td><code><?= $view->e($event['event_name']) ?></code></td>
                <td class="cell-nowrap"><span class="<?= $badge($event['state']) ?>"><?= $view->e($event['state_label']) ?></span></td>
                <td class="num mono"><?= $count($event['total']) ?></td>
                <td class="num mono"><?= $count($event['organic']) ?></td>
                <td class="small"><?= $view->e($event['reason']) ?></td>
                <?php if ($canEditProject): ?><td><form method="post" action="<?= $view->e($base . '/' . $event['id'] . '/delete') ?>" data-confirm="Remove this business event and its imported counts?"><?= $view->csrfField() ?><button class="button button-small button-danger" aria-label="<?= $view->e('Remove ' . $event['event_name']) ?>">Remove</button></form></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>

    <?php if ($canEditProject && count($events) < $measurement['max_events']): ?>
    <form method="post" action="<?= $view->e($base) ?>" class="business-event-form"><?= $view->csrfField() ?>
        <div class="field"><label for="business-kind">Counts as</label><select id="business-kind" name="kind">
            <?php foreach ($measurement['kinds'] as $kind => [$label, $suggested]): ?><option value="<?= $view->e($kind) ?>" data-event-name="<?= $view->e($suggested) ?>"><?= $view->e($label) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label for="business-event-name">GA4 event name</label><input id="business-event-name" name="event_name" required maxlength="40" pattern="[A-Za-z][A-Za-z0-9_]{0,39}" placeholder="purchase" list="business-event-names" autocomplete="off" spellcheck="false" aria-describedby="business-event-hint"></div>
        <datalist id="business-event-names"><option value="purchase"><option value="generate_lead"><option value="sign_up"><option value="form_submit"></datalist>
        <div class="field"><label for="business-event-label">Description <span class="muted">(optional)</span></label><input id="business-event-label" name="label" maxlength="120" placeholder="Seed order"></div>
        <button class="button button-primary">Add event</button>
    </form>
    <p class="muted small" id="business-event-hint">Use the exact event name from GA4 → Admin → Events (case matters). GA4's recommended names are <code>purchase</code>, <code>generate_lead</code> and <code>sign_up</code>. Up to <?= (int) $measurement['max_events'] ?> events.</p>
    <?php elseif ($events === []): ?>
    <p class="muted small">An editor of this project can define the business events.</p>
    <?php endif; ?>
</section>

<div class="insight-two-column business-measurement-detail">
    <section class="card" aria-labelledby="sessions-clicks-title">
        <h2 id="sessions-clicks-title">Sessions and clicks are different measures</h2>
        <dl class="measure-pair">
            <div><dt>GA4 Organic Search sessions</dt><dd class="mono"><?= $count($ga4['organic_sessions']) ?></dd><dd class="muted small"><?= (int) $ga4['covered_days'] ?>/<?= (int) $range['days'] ?> days imported · GA4 property time zone</dd></div>
            <div><dt>Search Console clicks (Google)</dt><dd class="mono"><?= $count($search['clicks'] ?? null) ?></dd><dd class="muted small"><?php if ($search === null): ?>Search Console is not available here<?php elseif ($search['partial'] && $search['clicks'] !== null): ?><strong>Partial:</strong> <?= (int) $search['covered_days'] ?> of <?= (int) $range['days'] ?> days (Search Console runs 2–3 days behind) · Pacific Time<?php else: ?><?= (int) $search['covered_days'] ?>/<?= (int) $range['days'] ?> days imported · Pacific Time<?php endif; ?></dd></div>
        </dl>
        <p class="muted small">A dash means no day of the period is imported, not zero; a partial count says how many days it covers. Sessions include Bing and other search engines, start only when the GA4 tag runs and consent allows it, and count a visitor who returns later as a new session. Clicks are Google results clicks. Expect the two to differ and never match them one to one.</p>
    </section>
    <section class="card" aria-labelledby="measurement-limits-title">
        <h2 id="measurement-limits-title">What SEOcc can and cannot see</h2>
        <ul class="measurement-limits">
            <?php foreach ($measurement['limitations'] as $limitation): ?><li><?= $view->e($limitation) ?></li><?php endforeach; ?>
        </ul>
    </section>
</div>

<?php if ($events !== [] && $ga4['linked'] && $ga4['credentials']): ?>
<section class="card" id="business-test-check" aria-labelledby="business-test-title">
    <h2 id="business-test-title">Check a test conversion</h2>
    <ol class="steps business-test-steps">
        <li>On your live site, make one conversion yourself: a test order, a test lead form or a test sign-up. SEOcc never buys, submits or sends anything.</li>
        <li>Within 30 minutes, start the check below. SEOcc reads GA4's realtime report (the last 30 minutes) at the next cron run. It sends no events.</li>
        <li>Refresh this page. A count above zero means GA4 received the event; it does not show which page or channel sent it.</li>
    </ol>
    <?php if ($canManage): ?>
    <form method="post" action="<?= $view->e($base . '/check') ?>" class="business-check-form"><?= $view->csrfField() ?>
        <div class="field"><label for="business-check-note">What you did <span class="muted">(optional)</span></label><input id="business-check-note" name="note" maxlength="500" placeholder="Test order #1042 at 14:05"></div>
        <button class="button">Check GA4 now</button>
    </form>
    <?php else: ?>
    <p class="muted small">Someone who manages integrations can run the check.</p>
    <?php endif; ?>
    <?php if ($measurement['checks'] !== []): ?>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls>
        <thead><tr><th scope="col">Requested (UTC)</th><th scope="col">Result</th><th scope="col">Note</th></tr></thead>
        <tbody>
        <?php foreach ($measurement['checks'] as $check): ?>
            <tr>
                <td class="mono cell-nowrap"><?= $view->e(substr($check['requested_at'], 0, 16)) ?></td>
                <td><?php if ($check['status'] === 'queued'): ?><span class="badge">Waiting for cron</span>
                    <?php elseif ($check['status'] === 'failed'): ?><span class="badge badge-error">Failed</span> <span class="small"><?= $view->e((string) $check['error']) ?></span>
                    <?php else: ?><?php foreach ($check['result'] ?? [] as $name => $value): ?><span class="<?= $value > 0 ? 'badge badge-ok' : 'badge badge-warning' ?>"><code><?= $view->e($name) ?></code> <?= $value > 0 ? $view->e(number_format($value) . ' received') : 'not received' ?></span> <?php endforeach; ?>
                    <?php endif; ?></td>
                <td class="small"><?= $view->e($check['note']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
</section>
<?php endif; ?>

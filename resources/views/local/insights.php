<?php
/**
 * The profile against the website, review handling and the local pack's categories (0.46.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $base
 * @var SeoControlCenter\Domain\Local\BusinessProfile $profile
 * @var SeoControlCenter\Domain\Local\NapCheck|null $nap
 * @var SeoControlCenter\Domain\Local\ReviewInsights|null $reviewInsights
 * @var SeoControlCenter\Domain\Local\CategoryGap|null $categoryGap
 */
use SeoControlCenter\Domain\Local\NapCheck;
use SeoControlCenter\Domain\Local\ReviewInsights;

$badge = static fn(string $status, string $missing = 'Not on the site'): string => match ($status) {
    NapCheck::OK => '<span class="badge badge-ok">Matches</span>',
    NapCheck::MISMATCH => '<span class="badge badge-warning">Differs</span>',
    NapCheck::MISSING => '<span class="badge badge-warning">' . $view->e($missing) . '</span>',
    default => '<span class="muted">—</span>',
};
$site = $nap?->site;
?>
<?php if ($nap !== null): ?>
    <section class="card" id="local-nap">
        <h2>Profile and website</h2>
        <p class="muted small">Google trusts business details that match everywhere. Compared with what your pages state in the latest completed site audit<?= $nap->pageUrl !== null ? ' (best match: ' . $view->e(preg_replace('#^https?://[^/]+#', '', $nap->pageUrl) ?: '/') . ')' : '' ?>.</p>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Detail</th><th scope="col">Google profile</th><th scope="col">Your website</th><th scope="col">Status</th></tr></thead>
                <tbody>
                    <tr><td>Name</td><td><?= $view->e($profile->title) ?></td><td><?= $view->e($site['name'] ?? '—') ?></td><td><?= $badge($nap->status('name')) ?></td></tr>
                    <tr><td>Phone</td><td><?= $view->e($profile->phone ?? '—') ?></td><td><?= $view->e($site !== null && $site['phones'] !== [] ? implode(', ', $site['phones']) : '—') ?></td><td><?= $badge($nap->status('phone')) ?></td></tr>
                    <tr><td>Address</td><td><?= $view->e($profile->address ?? '—') ?></td><td><?= $view->e($site['address'] ?? '—') ?></td><td><?= $badge($nap->status('address')) ?></td></tr>
                    <tr><td>Website</td><td class="key-cell"><?= $view->e($profile->url ?? '—') ?></td><td>This project’s domain</td><td><?= $badge($nap->status('website'), 'Not in the profile') ?></td></tr>
                    <tr><td>Business markup</td><td>—</td><td><?= $view->e($site !== null && $site['types'] !== [] ? implode(', ', $site['types']) : '—') ?></td><td><?= $nap->status('markup') === NapCheck::MISSING ? '<span class="badge badge-warning">No LocalBusiness markup</span>' : $badge($nap->status('markup')) ?></td></tr>
                </tbody>
            </table>
        </div>
        <?php if (!$nap->isKnown()): ?>
            <p class="small muted">Run a new <a href="<?= $view->e($base . '/site-audit') ?>">site audit</a> to compare your website with the profile; earlier audits did not record business details.</p>
        <?php elseif ($nap->problems() !== []): ?>
            <p class="small">Use the exact name, phone number and address from your profile on your contact page and in LocalBusiness structured data, and link the profile to this website.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card" id="local-reviews">
    <h2>Review handling</h2>
    <?php if ($reviewInsights === null): ?>
        <p class="muted small">Import your Google reviews on the <a href="<?= $view->e($base . '/local/locations') ?>">Locations</a> page to see how many get an answer, critical reviews still waiting, and how fast new reviews arrive.</p>
    <?php else: ?>
        <p class="muted small">From the <?= $view->e(number_format($reviewInsights->count)) ?> newest reviews imported<?= $reviewInsights->fetchedOn !== null ? ' on ' . $view->e($reviewInsights->fetchedOn) : '' ?>; a sample, not every review.</p>
        <dl class="details">
            <dt>Average rating</dt><dd><?= $reviewInsights->average !== null ? $view->e(number_format($reviewInsights->average, 1)) . ' ★' : '—' ?></dd>
            <dt>Owner replies</dt><dd><?= $view->e(number_format($reviewInsights->replied)) ?> of <?= $view->e(number_format($reviewInsights->count)) ?> (<?= $view->e((string) round($reviewInsights->replyShare() * 100)) ?>%)<?= $reviewInsights->count >= ReviewInsights::MIN_REVIEWS && $reviewInsights->replyShare() < 0.5 ? ' <span class="badge badge-warning">Answer more reviews</span>' : '' ?></dd>
            <dt>Critical reviews without a reply</dt><dd><?= $reviewInsights->unansweredCritical > 0 ? '<span class="badge badge-error">' . $view->e(number_format($reviewInsights->unansweredCritical)) . '</span>' : '<span class="badge badge-ok">None</span>' ?> <span class="muted small">of <?= $view->e(number_format($reviewInsights->critical)) ?> rated 2 ★ or lower</span></dd>
            <?php if ($reviewInsights->perMonth !== null): ?><dt>New reviews per month</dt><dd><?= $view->e(number_format($reviewInsights->perMonth, 1)) ?> <span class="muted small">over the 6 months before the import</span></dd><?php endif; ?>
            <?php if ($reviewInsights->ownVotes !== null && $reviewInsights->leaderVotes !== null): ?><dt>Reviews against the local pack</dt><dd><?= $view->e(number_format($reviewInsights->ownVotes)) ?> against about <?= $view->e(number_format($reviewInsights->leaderVotes)) ?> for the businesses in the top 3<?= $reviewInsights->behindLeaders() ? ' <span class="badge badge-warning">Behind</span>' : '' ?></dd><?php endif; ?>
        </dl>
        <?php if ($reviewInsights->unanswered !== []): ?>
            <h3>Answer these first</h3>
            <ul class="small">
                <?php foreach ($reviewInsights->unanswered as $r): ?>
                    <li><?= $view->e($r['author'] !== '' ? $r['author'] : 'Reviewer') ?> · <?= $view->e(number_format((float) $r['rating'], 0)) ?> ★<?= $r['published'] !== null ? ' · ' . $view->e($r['published']) : '' ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php if ($categoryGap !== null && $categoryGap->categories !== []): ?>
    <section class="card" id="local-categories">
        <h2>Categories in the local pack</h2>
        <p class="muted small">The primary category of the other businesses in the top 3 for your local keywords (latest check). Your category: <strong><?= $view->e($categoryGap->own ?? 'not set') ?></strong>.</p>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Category</th><th scope="col" class="num">Times in the top 3</th></tr></thead>
                <tbody>
                    <?php foreach (array_slice($categoryGap->categories, 0, 6, true) as $name => $n): ?>
                        <tr><td><?= $view->e((string) $name) ?><?= $categoryGap->own !== null && mb_strtolower((string) $name) === mb_strtolower($categoryGap->own) ? ' <span class="badge badge-ok">Yours</span>' : '' ?></td><td class="num"><?= $view->e(number_format($n)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($categoryGap->hasGap()): ?>
            <p class="small">Most businesses in the local pack use <strong><?= $view->e((string) $categoryGap->leading()) ?></strong>. If that describes what you offer, make it your primary category in Google Business Profile, or add it as a secondary one.</p>
        <?php endif; ?>
    </section>
<?php endif; ?>

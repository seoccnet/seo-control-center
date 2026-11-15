<?php

declare(strict_types=1);

/**
 * Countries next to the hreflang setup (1.2.1).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var array{codes: list<array{code: string, pages: int}>, regional: bool, hasDefault: bool, range: SeoControlCenter\Domain\Shared\DateRange, countries: list<array{country: string, region: ?string, clicks: int, impressions: int, versions: list<string>}>} $international
 */

$codes = $international['codes'];
?>
<section class="card" id="audit-international" aria-labelledby="audit-international-heading">
    <h2 id="audit-international-heading">Countries and language versions</h2>
    <p class="muted">The hreflang codes this audit found, with the number of pages that use each for themselves, and the countries that brought Search Console clicks from <?= $view->e($international['range']->fromString()) ?> to <?= $view->e($international['range']->toString()) ?>.</p>
    <p><?php foreach ($codes as $i => $c): ?><?= $i > 0 ? ', ' : '' ?><code><?= $view->e($c['code']) ?></code> <span class="muted small">(<?= $view->e(number_format($c['pages'])) ?> page<?= $c['pages'] === 1 ? '' : 's' ?>)</span><?php endforeach; ?><?= $international['hasDefault'] ? '' : ' · <span class="muted">no x-default</span>' ?></p>
    <?php if ($international['countries'] === []): ?>
    <p class="muted">No country data from Search Console for this period. Connect Search Console to compare.</p>
    <?php elseif (!$international['regional']): ?>
    <p class="muted">The site targets languages only (codes without a country), so each language version serves every country that speaks it. Add country codes such as <code>en-GB</code> and <code>en-US</code> only where content, prices or delivery differ by country.</p>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Country</th><th scope="col">Clicks</th><th scope="col">Impressions</th></tr></thead><tbody>
    <?php foreach ($international['countries'] as $row): ?><tr><td><?= $view->e($row['country']) ?></td><td><?= $view->e(number_format($row['clicks'])) ?></td><td><?= $view->e(number_format($row['impressions'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Country</th><th scope="col">Clicks</th><th scope="col">Impressions</th><th scope="col">Version for this country</th></tr></thead><tbody>
    <?php foreach ($international['countries'] as $row): ?>
        <tr><td><?= $view->e($row['country']) ?></td><td><?= $view->e(number_format($row['clicks'])) ?></td><td><?= $view->e(number_format($row['impressions'])) ?></td>
        <td><?php if ($row['versions'] !== []): ?><?= $view->e(implode(', ', $row['versions'])) ?><?php else: ?><span class="muted">None: served by a language-only version<?= $international['hasDefault'] ? ' or x-default' : '' ?></span><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <p class="muted small">A country without its own version is not a problem by itself: visitors get the version for their language. Add one when that country brings many clicks and needs different prices, delivery, spelling or legal content.</p>
    <?php endif; ?>
</section>

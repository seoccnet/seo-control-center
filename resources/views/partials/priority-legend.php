<?php
/**
 * Legend of the priority colour scale (Domain\Opportunities\PriorityBand).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string|null $caption
 */
use SeoControlCenter\Domain\Opportunities\PriorityBand;
?>
<div class="priority-legend" role="group" aria-label="Priority colour scale">
    <span class="priority-legend-caption"><?= $view->e($caption ?? 'Priority score') ?></span>
    <ol>
        <?php foreach (array_reverse(PriorityBand::cases()) as $band): ?>
            <li class="<?= $view->e($band->cssClass()) ?>"><span class="priority-dot" aria-hidden="true"></span><?= $view->e($band->label()) ?> <span class="muted"><?= $view->e($band->range()) ?></span></li>
        <?php endforeach; ?>
    </ol>
</div>

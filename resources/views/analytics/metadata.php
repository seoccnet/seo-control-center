<?php

declare(strict_types=1);

?>
<?php if ($metadata === null): ?><p>No GA4 history yet. Imports refresh the latest 28 days daily and retain earlier imports; failed imports are listed under System → Jobs.</p>
<?php else: ?><p class="muted"><?= $view->e($metadata['from']) ?> to <?= $view->e($metadata['to']) ?> in <?= $view->e($metadata['timezone']) ?>. Currency: <?= $view->e($metadata['currency']) ?>. Last project import <?= $view->e($metadata['fetched_at']) ?> UTC.</p>
<?php if ($metadata['limited']): ?><p role="status">GA4 data is incomplete: the 10,000 daily landing-page row limit or Google's “other” grouping was reached. Totals may be understated.</p><?php endif; ?>
<?php if ($metadata['thresholded']): ?><p role="status">Google applies privacy thresholds to this report; small totals may be withheld.</p><?php endif; ?>
<?php endif; ?>

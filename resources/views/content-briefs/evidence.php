<?php

declare(strict_types=1);

use SeoControlCenter\Modules\ContentBriefs\BriefBuilder;
?>
<section class="card brief-evidence"><h2><?= ($preview ?? false) ? 'Evidence preview' : 'Original evidence snapshot' ?></h2><p class="muted"><?= $view->e(BriefBuilder::NOTICE) ?></p><p class="small"><?= ($preview ?? false) ? 'Evidence is captured on save using the submitted target URL and source selections. Creating a new page without a stored URL leaves page data unavailable.' : 'This snapshot stays unchanged when you edit the target or instructions. Create a new brief to capture fresh evidence; review current Pages and Rankings before acting.' ?></p><details open><summary>Source evidence and limits</summary><?php if ($evidence === []): ?><p>No source snapshot yet. Save a valid brief to capture available evidence.</p><?php else: ?><ul><?php foreach ($evidence as $line): ?><li><?= $view->e($line) ?></li><?php endforeach; ?></ul><?php endif; ?></details></section>

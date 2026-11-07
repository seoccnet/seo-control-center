<?php
/** Context navigation shared by every page in Research, Improve and Visibility. */
$base='/projects/'.$project->publicId;
$groups=[
'Research'=>['pages'=>['Pages','/pages'],'inventory'=>['Content inventory','/inventory'],'keywords'=>['Keywords','/keywords'],'research'=>['Keyword research','/keywords/research'],'article-ideas'=>['Article ideas','/article-ideas'],'topic-plans'=>['Topic plans','/topic-plans'],'topics'=>['Topics & owners','/keywords/topics'],'on-file'=>['Research on file','/keywords/research/on-file'],'investigations'=>['Investigations','/investigations'],'rankings'=>['Rankings','/rankings'],'changes'=>['Results-page changes','/rankings/changes'],'competitors'=>['Competitors','/competitors']],
'Improve'=>['content-briefs'=>['Content briefs','/content-briefs'],'site-audit'=>['Site audit','/site-audit'],'internal-links'=>['Internal links','/site-audit/links'],'lab-tests'=>['Speed tests','/lab-tests'],'page-monitoring'=>['Page monitoring','/page-monitoring'],'backlinks'=>['Backlinks','/backlinks']],
'Manage'=>['annotations'=>['Change timeline','/annotations'],'data-quality'=>['Data quality','/data-quality'],'reports'=>['Reports','/reports'],'settings'=>['Settings','/edit']],
'Visibility'=>['indexing'=>['Google indexing','/indexing'],'ai-visibility'=>['AI visibility','/ai-visibility'],'local'=>['Local rankings','/local'],'locations'=>['Locations & reviews','/local/locations']],
];
$group=match($active){'pages','inventory','keywords','article-ideas','topic-plans','rankings','competitors'=>'Research','content-briefs','site-audit','lab-tests','page-monitoring','backlinks'=>'Improve','indexing','ai-visibility','local'=>'Visibility','annotations','data-quality','reports','settings'=>'Manage',default=>null};
if ($group===null) return;
$path=$view->path();$current=null;$length=0;
foreach($groups[$group] as $key=>[$label,$suffix])if(($path===$base.$suffix || str_starts_with($path,$base.$suffix.'/')) && strlen($suffix)>$length){$current=$key;$length=strlen($suffix);}
?>
<section class="module-context" aria-label="<?= $view->e($group) ?> navigation"><div><p class="portfolio-eyebrow"><?= $view->e($group) ?></p><p class="module-context-description"><?= $view->e(match($group){'Research'=>'Understand your pages, keywords and competition.','Improve'=>'Plan changes and review the evidence behind them.','Manage'=>'Record your work, share results and configure this project.','Visibility'=>'Follow how your website appears across search, AI and Maps.'}) ?></p></div><nav class="module-tabs" aria-label="<?= $view->e($group) ?> pages"><?php foreach($groups[$group] as $key=>[$label,$suffix]): ?><a href="<?= $view->e($base.$suffix) ?>"<?= $key===$current?' aria-current="page"':'' ?>><?= $view->e($label) ?></a><?php endforeach; ?></nav></section>


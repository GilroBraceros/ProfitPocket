<?php
declare(strict_types=1);

// Presentation only: each page supplies its existing authorized action URLs.
function record_actions(string $module, string $name, string $view_url, array $actions, ?array $primary=null, string $view_action='details'): void
{
    if (!in_array($module, ['sale','expense','customer','utang','product','admin'], true)) { throw new InvalidArgumentException('Unknown action module.'); }
    if ($primary) { array_unshift($actions,['details','View Details',$view_url,false]); }
    ?>
    <div class="record-actions dropdown">
        <a class="record-action-view" data-<?= e($module) ?>-modal="<?= e($primary[0]??$view_action) ?>" href="<?= e($primary[2]??$view_url) ?>" aria-label="<?= e(($primary[1]??'View details').' for '.$name) ?>"><?= e($primary[1]??'View') ?></a>
        <?php if ($actions): ?>
        <button class="record-action-more dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for <?= e($name) ?>" title="More actions"><span aria-hidden="true">⋯</span></button>
        <ul class="dropdown-menu dropdown-menu-end record-actions-menu">
            <?php foreach ($actions as [$action,$label,$url,$danger]): ?>
            <li><a class="dropdown-item <?= $danger?'text-danger':'' ?>" data-<?= e($module) ?>-modal="<?= e($action) ?>" href="<?= e($url) ?>"><?= e($label) ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php
}

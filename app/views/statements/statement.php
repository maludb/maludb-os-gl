<?php /** One statement (screen `statement`). Data: slug, title, table, range, mode, cmp, dept, site, pt, hasBooks, periods, departments, sites, currency, mixed */
$asOfOnly = in_array($slug, ['balance-sheet', 'trial-balance'], true); ?>
<?= view('shared/header.php', ['id' => 'statement', 'title' => $title, 'crumbs' => [['Home', '/'], ['Statements', '/statements/'], [$title, null]], 'back' => back_link() ?? ['/statements/', 'Statements']]) ?>
<div class="main-content" id="statement-content" data-statement="<?= e($slug) ?>">
    <?= view('statements/partials/posted-through.php', ['pt' => $pt, 'currency' => $currency, 'mixed' => $mixed]) ?>
    <?= view('statements/partials/period-picker.php', ['action' => '/statements/' . $slug, 'range' => $range, 'periods' => $periods, 'asOf' => $asOfOnly, 'mode' => $slug === 'cash-flow' || $slug === 'trial-balance' ? null : $mode, 'dept' => $dept, 'site' => $site,
        'departments' => $slug === 'pl' ? $departments : [], 'sites' => $sites]) ?>
    <?php if (!$hasBooks): ?>
        <div class="card"><div class="card-body text-center text-muted py-4" id="statement-no-books">No books for that period.</div></div>
    <?php else: ?>
        <?= view('statements/partials/statement-table.php', ['table' => $table, 'id' => 'statement']) ?>
        <?php if ($cmp !== null && $slug !== 'balance-sheet'): ?><div class="fs-12 text-muted" id="statement-compare-note">Prior column: <?= e($cmp['label']) ?>.</div><?php endif; ?>
    <?php endif; ?>
</div>

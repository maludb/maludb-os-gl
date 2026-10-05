<?php /** The fiscal years and their periods (screen `periods-list`). Data: years, may, notice, here, tz */ ?>
<?= view('shared/header.php', ['id' => 'periods-list', 'title' => 'Periods', 'crumbs' => [['Home', '/'], ['Periods', null]],
    'action' => $may['manage'] ? hx_link('/periods/new', '<i class="feather-plus me-1"></i>Open a fiscal year', 'btn btn-primary btn-touch', 'id="periods-list-add-btn"') : '']) ?>
<div class="main-content" id="periods-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($years === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="periods-empty">No fiscal year yet — open the first one to start posting.</div></div><?php endif; ?>
    <div id="periods-list-cards"><?php foreach ($years as $y): ?><?= view('periods/partials/year-card.php', ['y' => $y, 'may' => $may, 'here' => $here, 'tz' => $tz]) ?><?php endforeach; ?></div>
</div>

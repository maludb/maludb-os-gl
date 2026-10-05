<?php /** The AI statements (screen `ai-statements`). Data: rows, kernelError, may, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'ai-statements', 'title' => 'AI statements', 'crumbs' => [['Home', '/'], ['AI statements', null]],
    'action' => $may['pull'] ? '<form method="post" action="/ap/ai/pull.php" hx-post="/ap/ai/pull.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<button type="submit" class="btn btn-primary btn-touch" id="ai-statements-pull-btn"><i class="feather-download-cloud me-1"></i>Pull now</button></form>' : '']) ?>
<div class="main-content" id="ai-statements-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('ai/partials/statement-cards.php', ['rows' => $rows, 'kernelError' => $kernelError, 'may' => $may, 'here' => $here]) ?>
</div>

<?php /** The four statements (screen `statements`). Data: cards, range, pt, currency, mixed */ ?>
<?= view('shared/header.php', ['id' => 'statements', 'title' => 'Statements', 'crumbs' => [['Home', '/'], ['Statements', null]]]) ?>
<div class="main-content" id="statements-content">
    <?= view('statements/partials/posted-through.php', ['pt' => $pt, 'currency' => $currency, 'mixed' => $mixed]) ?>
    <div class="fs-12 text-muted mb-2" id="statements-period">For <?= e($range['label']) ?> · <?= e($currency) ?></div>
    <div class="row g-3" id="statements-cards">
        <?php foreach ($cards as $c): ?>
            <div class="col-12 col-md-6"><div class="card h-100" id="statement-card-<?= e($c['key']) ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div class="d-flex align-items-center gap-2"><span class="avatar-text avatar-md rounded bg-soft-primary text-primary"><i class="<?= e($c['icon']) ?>"></i></span><div class="fw-bold"><?= e($c['title']) ?></div></div>
                    <?php if ($c['state'] !== null): ?><span class="badge bg-<?= $c['state']['ok'] ? 'success' : 'danger' ?>" id="statement-card-<?= e($c['key']) ?>-state"><?= e($c['state']['text']) ?></span><?php endif; ?>
                </div>
                <div class="fs-20 fw-bold mt-2" id="statement-card-<?= e($c['key']) ?>-figure"><?= e(fmt_money($c['figure'], $currency)) ?></div>
                <div class="fs-12 text-muted"><?= e($c['label']) ?></div>
                <div class="mt-2"><?= hx_link($c['url'], 'Open', 'btn btn-light btn-touch w-100', 'id="statement-card-' . e($c['key']) . '-open-btn"') ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 mt-3"><?= hx_link('/reports/ar-aging', 'Who owes us', 'me-3') ?><?= hx_link('/reports/ap-aging', 'Whom we owe', 'me-3') ?><?= hx_link('/reports/sales-tax', 'Sales tax', 'me-3') ?><?= hx_link('/reports/spend', 'Spend', 'me-3') ?><?= hx_link('/reports/revenue', 'Revenue', 'me-3') ?><?= hx_link('/reports/control-check', 'Control check') ?></div>
</div>

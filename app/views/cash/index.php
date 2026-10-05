<?php /** The bank accounts (screen `banks`). Data: banks, totals, may, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'banks', 'title' => 'Bank accounts', 'crumbs' => [['Home', '/'], ['Bank accounts', null]],
    'action' => $may['write'] ? hx_link('/cash/transfers/new', '<i class="feather-repeat me-1"></i>Transfer', 'btn btn-light btn-touch', 'id="banks-transfer-btn"') : '']) ?>
<div class="main-content" id="banks-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($totals !== []): ?><div class="d-flex flex-wrap gap-3 mb-3 fs-12" id="banks-totals"><?php foreach ($totals as $t): ?><span><span class="text-muted">Total in <?= e($t['currency']) ?>:</span> <span class="fw-bold" id="banks-total-<?= e($t['currency']) ?>"><?= e(fmt_money($t['ledger_balance'], $t['currency'])) ?></span></span><?php endforeach; ?></div><?php endif; ?>
    <div class="row g-3" id="banks-cards">
        <?php if ($banks === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="banks-empty">No bank account yet.<?= $may['settings'] ? '<div class="mt-2">' . hx_link('/settings/banks/new', 'Add a bank account', 'btn btn-primary btn-touch', 'id="banks-empty-add-btn"') . '</div>' : '' ?></div></div></div><?php endif; ?>
        <?php foreach ($banks as $b): ?><div class="col-12 col-md-6 col-xl-4"><?= view('cash/partials/bank-card.php', ['b' => $b, 'may' => $may, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
    <?php if ($may['settings'] && $banks !== []): ?><div class="mt-3 fs-12"><?= hx_link('/settings/banks/', 'Bank account settings', 'me-3', 'id="banks-settings-link"') ?><?= hx_link('/cash/transfers/', 'Transfers', '', 'id="banks-transfers-link"') ?></div><?php endif; ?>
</div>

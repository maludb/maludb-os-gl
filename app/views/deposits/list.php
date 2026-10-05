<?php /** The deposits (screen `deposits-list`). Data: rows, filters, banks, may, here, undeposited, notice */ ?>
<?= view('shared/header.php', ['id' => 'deposits-list', 'title' => 'Deposits', 'crumbs' => [['Home', '/'], ['Deposits', null]],
    'action' => $may['write'] ? hx_link('/deposits/new', '<i class="feather-plus me-1"></i>New deposit', 'btn btn-primary btn-touch', 'id="deposits-list-add-btn"') : '']) ?>
<div class="main-content" id="deposits-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2" id="deposits-undeposited"><?= $undeposited ?> receipt<?= $undeposited === 1 ? '' : 's' ?> waiting in Undeposited funds. <?= hx_link('/receipts/?undeposited=1', 'See them') ?></div>
    <div class="row g-3" id="deposits-list-cards">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="deposits-empty">No deposit yet.</div></div></div><?php endif; ?>
        <?php foreach ($rows as $d): $id = (int) $d['deposit_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="deposit-card-<?= $id ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><?= hx_link(with_back('/deposits/' . $id, $here), e($d['number']), 'text-dark') ?></div><span class="badge bg-<?= DEPOSIT_STATUS_BADGE[$d['status']] ?>"><?= e($d['status']) ?></span></div>
                <div class="fs-12 text-muted"><?= e($d['bank_account_name']) ?> · <?= e(format_date($d['deposit_date'])) ?></div>
                <div class="mt-1 fw-semibold"><?= e(fmt_money($d['total'], $d['currency'])) ?> <span class="fs-12 text-muted fw-normal"><?= (int) $d['receipt_count'] ?> receipt<?= (int) $d['receipt_count'] === 1 ? '' : 's' ?></span></div>
                <div class="mt-2"><?= hx_link(with_back('/deposits/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="deposit-card-' . $id . '-open-btn"') ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>

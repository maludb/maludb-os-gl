<?php /** The transfers (screen `transfers-list`). Data: rows, filters, banks, may, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'transfers-list', 'title' => 'Transfers', 'crumbs' => [['Home', '/'], ['Bank accounts', '/cash/'], ['Transfers', null]],
    'action' => $may['write'] ? hx_link('/cash/transfers/new', '<i class="feather-plus me-1"></i>Record a transfer', 'btn btn-primary btn-touch', 'id="transfers-list-add-btn"') : '']) ?>
<div class="main-content" id="transfers-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/cash/transfers/" class="card mb-3" id="transfers-filters"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-8"><label class="form-label fs-12 text-muted" for="transfers-filter-bank">Bank account</label><select name="bank_account" id="transfers-filter-bank" class="form-select btn-touch"><option value="">Every account</option><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>" <?= $filters['bank_account'] === $b['bank_account_id'] ? 'selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-4"><button type="submit" class="btn btn-light btn-touch w-100" id="transfers-filter-btn">Show</button></div>
    </div></form>
    <div class="row g-3" id="transfers-cards">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="transfers-empty">No transfer yet.</div></div></div><?php endif; ?>
        <?php foreach ($rows as $t): $id = (int) $t['transfer_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="transfer-card-<?= $id ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><?= hx_link(with_back('/cash/transfers/' . $id, $here), e($t['number']), 'text-dark') ?></div><span class="badge bg-<?= $t['status'] === 'void' ? 'dark' : 'success' ?>"><?= e($t['status']) ?></span></div>
                <div class="fs-12 text-muted"><?= e($t['from_bank_name']) ?> → <?= e($t['to_bank_name']) ?> · <?= e(format_date($t['transfer_date'])) ?></div>
                <div class="mt-1 fw-semibold"><?= e(fmt_money($t['amount'], $t['currency'])) ?></div><?= $t['memo'] ? '<div class="fs-12 text-muted">' . e($t['memo']) . '</div>' : '' ?>
                <div class="mt-2"><?= hx_link(with_back('/cash/transfers/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="transfer-card-' . $id . '-open-btn"') ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>

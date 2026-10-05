<?php /** The payment runs (screen `payment-runs-list`). Data: rows, filters, may, here */ ?>
<?= view('shared/header.php', ['id' => 'payment-runs-list', 'title' => 'Payment runs', 'crumbs' => [['Home', '/'], ['Payment runs', null]],
    'action' => $may['write'] ? hx_link('/payment-runs/new', '<i class="feather-plus me-1"></i>New payment run', 'btn btn-primary btn-touch', 'id="payment-runs-list-add-btn"') : '']) ?>
<div class="main-content" id="payment-runs-list-content">
    <div class="mb-3 d-flex flex-wrap gap-2" id="payment-runs-tabs"><?php foreach (['' => 'All', 'draft' => 'Draft', 'approved' => 'Approved', 'recorded' => 'Recorded', 'void' => 'Void'] as $k => $label): ?><?= hx_link('/payment-runs/' . ($k === '' ? '' : '?status=' . $k), $label, 'btn btn-sm ' . ($filters['status'] === $k ? 'btn-primary' : 'btn-light'), 'id="payment-runs-tab-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?></div>
    <div class="row g-3" id="payment-runs-list-cards">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="payment-runs-empty">No payment run yet.</div></div></div><?php endif; ?>
        <?php foreach ($rows as $r): $id = (int) $r['payment_run_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="payment-run-card-<?= $id ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><?= hx_link(with_back('/payment-runs/' . $id, $here), e($r['number']), 'text-dark') ?></div><span class="badge bg-<?= RUN_STATUS_BADGE[$r['status']] ?>"><?= e($r['status']) ?></span></div>
                <div class="fs-12 text-muted"><?= e($r['bank_account_name']) ?> · pay date <?= e(format_date($r['pay_date'])) ?></div>
                <div class="mt-1 fw-semibold"><?= e(fmt_money($r['total'], $r['currency'])) ?> <span class="fs-12 text-muted fw-normal"><?= (int) $r['payment_count'] ?> payment<?= (int) $r['payment_count'] === 1 ? '' : 's' ?></span></div>
                <div class="mt-2"><?= hx_link(with_back('/payment-runs/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="payment-run-card-' . $id . '-open-btn"') ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>

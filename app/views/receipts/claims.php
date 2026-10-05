<?php /** What customers said on the link (screen `claims`). Data: rows, status, may, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'claims', 'title' => 'Said paid', 'crumbs' => [['Home', '/'], ['Receipts', '/receipts/'], ['Said paid', null]], 'back' => back_link() ?? ['/receipts/', 'receipts']]) ?>
<div class="main-content" id="claims-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3 d-flex flex-wrap gap-2" id="claims-tabs"><?php foreach (['submitted' => 'Waiting', 'confirmed' => 'Confirmed', 'dismissed' => 'Dismissed', 'all' => 'All'] as $k => $label): ?><?= hx_link('/receipts/claims?status=' . $k, $label, 'btn btn-sm ' . ($status === $k ? 'btn-primary' : 'btn-light'), 'id="claims-tab-' . $k . '"') ?><?php endforeach; ?></div>
    <div id="claims-list">
        <?php if ($rows === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="claims-empty">Nothing here.</div></div><?php endif; ?>
        <?php foreach ($rows as $k): $kid = $k['receipt_claim_id']; ?>
            <div class="card mb-2" id="claim-card-<?= $kid ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2"><div><?= hx_link(with_back('/invoices/' . $k['invoice_id'], $here), e((string) $k['invoice_number']), 'fw-bold text-dark') ?> <span class="text-muted">· <?= e($k['customer_name']) ?></span></div>
                    <span class="badge bg-<?= ['submitted' => 'warning text-dark', 'confirmed' => 'success', 'dismissed' => 'secondary'][$k['status']] ?>"><?= e($k['status']) ?></span></div>
                <div class="mt-1"><?= $k['amount'] !== null ? e(fmt_money($k['amount'], 'USD', false)) : 'No amount stated' ?> by <?= e($k['method']) ?> on <?= e(format_date($k['claimed_on'])) ?><?= $k['reference'] ? ' · ' . e($k['reference']) : '' ?></div>
                <?php if ($k['note']): ?><div class="fs-12 text-muted">“<?= e($k['note']) ?>”</div><?php endif; ?>
                <?php if ($may['write'] && $k['status'] === 'submitted'): ?>
                    <div class="d-flex flex-wrap gap-2 mt-2"><?= hx_link(with_back('/receipts/new?claim=' . $kid, $here), 'Confirm', 'btn btn-primary btn-touch', 'id="claim-' . $kid . '-confirm-btn"') ?>
                        <form method="post" action="/receipts/claim-dismiss.php" hx-post="/receipts/claim-dismiss.php" hx-target="#flash" class="d-flex gap-2 flex-grow-1" hx-confirm="Dismiss this claim?"><?= csrf_field() ?><input type="hidden" name="claim" value="<?= $kid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <input type="text" name="reason" class="form-control btn-touch" placeholder="Why" maxlength="300" id="claim-<?= $kid ?>-reason"><button type="submit" class="btn btn-light btn-touch" id="claim-<?= $kid ?>-dismiss-btn">Dismiss</button></form></div>
                <?php endif; ?>
            </div></div>
        <?php endforeach; ?>
    </div>
</div>

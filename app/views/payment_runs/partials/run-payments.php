<?php /** The payments of a run, each with its bills; add and remove while a draft. Data: run, more, may, here */ $cur = $run['currency']; $id = (int) $run['payment_run_id']; ?>
<div class="card mb-3" id="run-payments"><div class="card-header"><h5 class="card-title mb-0">Payments</h5></div>
    <?php if ($run['payments'] === []): ?><div class="card-body text-muted" id="run-payments-empty">No payment in this run.</div><?php else: ?>
    <div class="list-group list-group-flush"><?php foreach ($run['payments'] as $p): $pid = $p['payment_id']; ?>
        <div class="list-group-item<?= $p['status'] === 'void' ? ' opacity-50' : '' ?>" id="run-payment-<?= $pid ?>">
            <div class="d-flex justify-content-between align-items-start gap-2"><div class="min-w-0"><?= hx_link(with_back('/payments/' . $pid, $here), e($p['number']), 'fw-semibold') ?> <span class="badge bg-<?= PAYMENT_STATUS_BADGE[$p['status']] ?>"><?= e($p['status']) ?></span>
                <div class="fs-12 text-muted"><?= hx_link(with_back('/vendors/' . $p['vendor_id'], $here), e($p['vendor_name']), 'text-muted') ?> · <?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?><?= $p['cheque_number'] ? ' #<span id="run-payment-' . $pid . '-cheque">' . e($p['cheque_number']) . '</span>' : '' ?></div>
                <div class="fs-12 text-muted">Pays <?= e(implode(', ', array_column($p['bills'], 'number'))) ?></div></div>
                <div class="text-end text-nowrap"><div><?= e(fmt_money($p['amount'], $cur)) ?></div>
                <?php if ($run['status'] === 'draft' && $may['write']): ?><form method="post" action="/payment-runs/remove.php" hx-post="/payment-runs/remove.php" hx-target="#flash" hx-confirm="Take this payment out of the run?"><?= csrf_field() ?><input type="hidden" name="payment_run" value="<?= $id ?>"><input type="hidden" name="payment" value="<?= $pid ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light mt-1" id="run-payment-<?= $pid ?>-remove-btn">Remove</button></form><?php endif; ?></div></div>
        </div>
    <?php endforeach; ?></div><?php endif; ?>
    <?php if ($run['status'] === 'draft' && $may['write'] && $more !== []): ?>
    <div class="card-body border-top"><form method="post" action="/payment-runs/add.php" hx-post="/payment-runs/add.php" hx-target="#flash" class="row g-2 align-items-end" id="payment-run-add-form"><?= csrf_field() ?><input type="hidden" name="payment_run" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
        <div class="col-8"><label class="form-label fs-12 text-muted" for="payment-run-add-payment">Add a draft payment of this bank account</label><select name="payment" id="payment-run-add-payment" class="form-select btn-touch"><?php foreach ($more as $m): ?><option value="<?= $m['payment_id'] ?>"><?= e($m['number']) ?> — <?= e($m['vendor_name']) ?> — <?= e(fmt_money($m['amount'], $m['currency'])) ?></option><?php endforeach; ?></select></div>
        <div class="col-4"><button type="submit" class="btn btn-light btn-touch w-100" id="payment-run-add-btn">Add</button></div></form></div>
    <?php endif; ?>
</div>

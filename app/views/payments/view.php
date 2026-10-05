<?php /** One payment (screen `payment-view`). Data: p, more, attachments, notes, timeline, may, here, tz, notice */
$id = (int) $p['payment_id']; $cur = $p['currency']; $st = $p['status']; $title = $p['number']; ?>
<?= view('shared/header.php', ['id' => 'payment-view', 'title' => $title, 'crumbs' => [['Home', '/'], ['Payments', '/payments/'], [$title, null]], 'back' => back_link() ?? ['/payments/', 'payments']]) ?>
<div class="main-content" id="payment-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="payment-head"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fs-20 fw-bold" id="payment-head-amount"><?= e(fmt_money($p['amount'], $cur)) ?></div><span class="badge bg-<?= PAYMENT_STATUS_BADGE[$st] ?>" id="payment-head-status"><?= e(PAYMENT_STATUSES[$st]) ?></span></div>
                <div class="mt-1"><?= hx_link(with_back('/vendors/' . (int) $p['vendor_id'], $here), e($p['vendor_name']), 'fw-semibold text-dark') ?></div>
                <dl class="row mb-0 fs-12 mt-2">
                    <dt class="col-5 text-muted"><?= $st === 'recorded' ? 'Paid on' : 'Payment date' ?></dt><dd class="col-7" id="payment-head-date"><?= e(format_date($p['payment_date'])) ?> · <?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?><?= $p['cheque_number'] ? ' #' . e($p['cheque_number']) : '' ?></dd>
                    <dt class="col-5 text-muted">From</dt><dd class="col-7" id="payment-head-bank"><?= e($p['bank_account_name']) ?></dd>
                    <?php if ($p['reference']): ?><dt class="col-5 text-muted">Reference</dt><dd class="col-7"><?= e($p['reference']) ?></dd><?php endif; ?>
                    <dt class="col-5 text-muted">Applied</dt><dd class="col-7" id="payment-head-unapplied"><?= e(fmt_money($p['amount_applied'], $cur)) ?><?= m_cmp($p['unapplied'], '0') > 0 && in_array($st, ['draft', 'approved'], true) ? ' · <span class="badge bg-soft-warning text-warning">' . e(fmt_money($p['unapplied'], $cur)) . ' unapplied</span>' : '' ?></dd>
                    <?php if ($p['payment_run_id'] !== null): ?><dt class="col-5 text-muted">Run</dt><dd class="col-7"><?= hx_link(with_back('/payment-runs/' . (int) $p['payment_run_id'], $here), e((string) $p['payment_run_number']), '', 'id="payment-head-run-link"') ?></dd><?php endif; ?>
                    <?php if ($p['journal_entry_id'] !== null): ?><dt class="col-5 text-muted">Entry</dt><dd class="col-7"><?= hx_link(with_back('/journal/' . (int) $p['journal_entry_id'], $here), e((string) ($p['journal_entry_number'] ?? 'the entry')), '', 'id="payment-head-entry-link"') ?></dd><?php endif; ?>
                    <?php if ($p['matched_line'] !== null): ?><dt class="col-5 text-muted">Bank line</dt><dd class="col-7" id="payment-head-matched">Matched on <?= e(format_date($p['matched_line']['txn_date'])) ?></dd><?php elseif ($st === 'recorded'): ?><dt class="col-5 text-muted">Bank line</dt><dd class="col-7" id="payment-head-unmatched"><?= hx_link('/cash/match?bank_account=' . (int) $p['bank_account_id'], 'Match it when it shows at the bank') ?></dd><?php endif; ?>
                    <?php if ($p['memo']): ?><dt class="col-5 text-muted">Memo</dt><dd class="col-7"><?= e($p['memo']) ?></dd><?php endif; ?>
                    <?php if ($st === 'void'): ?><dt class="col-5 text-muted">Void</dt><dd class="col-7" id="payment-head-void"><?= e((string) $p['void_reason']) ?></dd><?php endif; ?>
                </dl>
                <div class="fs-12 text-muted mt-2">By <?= e($p['created_by_name'] ?? '?') ?><?= ($p['created_by_kind'] ?? '') === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?>, <?= e(format_ts($p['created_at'], $tz)) ?><?= $p['recorded_at'] ? ' · recorded by ' . e((string) $p['recorded_by_name']) . ', ' . e(format_ts($p['recorded_at'], $tz)) : '' ?></div>
            </div></div>
            <?php if (($may['write'] || $may['approve'] || $may['override']) && $st !== 'void'): ?>
            <div class="card mb-3" id="payment-actions"><div class="card-body">
                <?php if ($st === 'draft'): ?>
                    <?php if ($may['approve']): ?><form method="post" action="/payments/approve.php" hx-post="/payments/approve.php" hx-target="#flash" hx-confirm="Approve this payment? Once approved, the vendor, amount and bank account are fixed." class="mb-2"><?= csrf_field() ?><input type="hidden" name="payment" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="payment-approve-btn">Approve</button></form>
                    <?php else: ?><div class="fs-12 text-muted mb-2" id="payment-awaiting-note">Awaiting approval by the controller.</div><?php endif; ?>
                    <?php if ($may['write']): ?><form method="post" action="/payments/delete.php" hx-post="/payments/delete.php" hx-target="#flash" hx-confirm="Delete this draft payment?"><?= csrf_field() ?><input type="hidden" name="payment" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="payment-delete-btn">Delete the draft</button></form><?php endif; ?>
                <?php elseif ($st === 'approved' && $may['write']): ?>
                    <form method="post" action="/payments/record.php" hx-post="/payments/record.php" hx-target="#flash" hx-confirm="Record this payment as paid? Do this once the money has left the account." class="mb-2" id="payment-record-form"><?= csrf_field() ?><input type="hidden" name="payment" value="<?= $id ?>">
                        <label class="form-label fs-12 text-muted" for="payment-record-date">When did the money leave the account?</label><input type="date" name="payment_date" id="payment-record-date" class="form-control btn-touch mb-2" value="<?= e($p['payment_date']) ?>">
                        <?php if ($p['method'] === 'cheque'): ?><input type="text" name="cheque_number" class="form-control btn-touch mb-2" placeholder="Cheque number" maxlength="40" value="<?= e((string) $p['cheque_number']) ?>" id="payment-record-cheque"><?php endif; ?>
                        <input type="text" name="reference" class="form-control btn-touch mb-2" placeholder="Reference (optional)" maxlength="120" value="<?= e((string) $p['reference']) ?>" id="payment-record-reference">
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="payment-record-btn">Record as paid</button></form>
                <?php endif; ?>
                <?php if (in_array($st, ['approved', 'recorded'], true) && $may['override']): ?><form method="post" action="/payments/void.php" hx-post="/payments/void.php" hx-target="#flash" hx-confirm="Void this payment? Its entry is reversed and the bills are owed again." class="mt-2"><?= csrf_field() ?><input type="hidden" name="payment" value="<?= $id ?>">
                    <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is voided" maxlength="500" required id="payment-void-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="payment-void-btn">Void</button></form><?php endif; ?>
            </div></div>
            <?php endif; ?>
            <?= view('shared/attachments.php', ['type' => 'payment', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'payment']) ?>
            <?= view('shared/notes.php', ['type' => 'payment', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'payment']) ?>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="payment-applications"><div class="card-header"><h5 class="card-title mb-0">Pays</h5></div>
                <?php if ($p['applications'] === []): ?><div class="card-body text-muted" id="payment-applications-empty">Not applied to a bill.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($p['applications'] as $a): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="payment-application-<?= $a['bill_id'] ?>"><span><?= hx_link(with_back('/bills/' . $a['bill_id'], $here), e((string) $a['bill_number'])) ?><?= $a['vendor_ref'] ? ' <span class="fs-12 text-muted">' . e($a['vendor_ref']) . '</span>' : '' ?></span>
                        <span class="d-flex align-items-center gap-2"><span><?= e(fmt_money($a['amount'], $cur)) ?></span>
                        <?php if ($may['write'] && in_array($st, ['draft', 'approved'], true)): ?><form method="post" action="/payments/unapply.php" hx-post="/payments/unapply.php" hx-target="#flash" hx-confirm="Take this payment off the bill?"><?= csrf_field() ?><input type="hidden" name="payment" value="<?= $id ?>"><input type="hidden" name="bill" value="<?= $a['bill_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light" id="payment-application-<?= $a['bill_id'] ?>-unapply-btn">Unapply</button></form><?php endif; ?></span></div>
                <?php endforeach; ?></div><?php endif; ?>
                <?php if ($may['write'] && in_array($st, ['draft', 'approved'], true) && $more !== [] && m_cmp($p['unapplied'], '0') > 0): ?>
                <div class="card-body border-top"><form method="post" action="/payments/apply.php" hx-post="/payments/apply.php" hx-target="#flash" class="row g-2 align-items-end" id="payment-apply-form"><?= csrf_field() ?><input type="hidden" name="payment" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="payment-apply-bill">Apply to</label><select name="bill" id="payment-apply-bill" class="form-select btn-touch"><?php foreach ($more as $b): ?><option value="<?= $b['bill_id'] ?>"><?= e((string) $b['number']) ?> — <?= e(fmt_money($b['payable'], $b['currency'])) ?> to pay</option><?php endforeach; ?></select></div>
                    <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="payment-apply-amount">Amount (empty: what fits)</label><input type="text" inputmode="decimal" name="amount" id="payment-apply-amount" class="form-control btn-touch"></div>
                    <div class="col-6 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="payment-apply-btn">Apply</button></div></form></div>
                <?php endif; ?>
            </div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'payment']) ?>
        </div>
    </div>
</div>

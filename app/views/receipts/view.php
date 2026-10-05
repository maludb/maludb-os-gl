<?php /** One receipt (screen `receipt-view`). Data: r, open, attachments, notes, timeline, may, here, tz, notice */ $id = (int) $r['receipt_id']; $cur = $r['currency']; $void = $r['status'] === 'void'; ?>
<?= view('shared/header.php', ['id' => 'receipt-view', 'title' => $r['number'], 'crumbs' => [['Home', '/'], ['Receipts', '/receipts/'], [$r['number'], null]], 'back' => back_link() ?? ['/receipts/', 'receipts']]) ?>
<div class="main-content" id="receipt-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="receipt-head"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fs-20 fw-bold" id="receipt-head-amount"><?= e(fmt_money($r['amount'], $cur)) ?></div><span class="badge bg-<?= $void ? 'dark' : 'success' ?>" id="receipt-head-status"><?= $void ? 'Void' : 'Recorded' ?></span></div>
                <div class="mt-1"><?= hx_link(with_back('/customers/' . (int) $r['customer_id'], $here), e($r['customer_name']), 'fw-semibold text-dark') ?></div>
                <dl class="row mb-0 fs-12 mt-2">
                    <dt class="col-5 text-muted">Received</dt><dd class="col-7"><?= e(format_date($r['received_on'])) ?> · <?= e(RECEIPT_METHODS[$r['method']] ?? $r['method']) ?></dd>
                    <?php if ($r['reference']): ?><dt class="col-5 text-muted">Reference</dt><dd class="col-7"><?= e($r['reference']) ?></dd><?php endif; ?>
                    <dt class="col-5 text-muted">Applied</dt><dd class="col-7" id="receipt-head-unapplied"><?= (float) $r['unapplied'] > 0 && !$void ? e(fmt_money(m_sub($r['amount'], $r['unapplied']), $cur)) . ' · <span class="badge bg-soft-warning text-warning">' . e(fmt_money($r['unapplied'], $cur)) . ' unapplied</span>' : e(fmt_money($void ? '0' : $r['amount'], $cur)) ?></dd>
                    <dt class="col-5 text-muted">Where</dt><dd class="col-7" id="receipt-head-where"><?= $r['deposit_id'] !== null ? 'In ' . hx_link(with_back('/deposits/' . (int) $r['deposit_id'], $here), e((string) $r['deposit_number'])) : ($r['deposit_to_bank_account_id'] !== null ? e((string) $r['deposit_to_bank_name']) . ' (straight to the bank)' : 'Undeposited funds') ?></dd>
                    <?php if ($r['journal_entry_id'] !== null): ?><dt class="col-5 text-muted">Entry</dt><dd class="col-7"><?= hx_link(with_back('/journal/' . (int) $r['journal_entry_id'], $here), e((string) ($r['journal_entry_number'] ?? 'the entry')), '', 'id="receipt-head-entry-link"') ?></dd><?php endif; ?>
                    <?php if ($r['claim'] !== null): ?><dt class="col-5 text-muted">From a claim</dt><dd class="col-7">On <?= e((string) $r['claim']['invoice_number']) ?>, <?= e($r['claim']['status']) ?></dd><?php endif; ?>
                    <?php if ($r['memo']): ?><dt class="col-5 text-muted">Memo</dt><dd class="col-7"><?= e($r['memo']) ?></dd><?php endif; ?>
                    <?php if ($void): ?><dt class="col-5 text-muted">Void</dt><dd class="col-7" id="receipt-head-void"><?= e($r['void_reason'] ?? '') ?></dd><?php endif; ?>
                </dl>
            </div></div>
            <?php if (!$void && $may['override']): ?>
                <div class="card mb-3" id="receipt-actions"><div class="card-body"><form method="post" action="/receipts/void.php" hx-post="/receipts/void.php" hx-target="#flash" hx-confirm="Void this receipt? Its entry is reversed."><?= csrf_field() ?><input type="hidden" name="receipt" value="<?= $id ?>">
                    <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is voided" maxlength="500" required id="receipt-void-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="receipt-void-btn">Void</button></form></div></div>
            <?php endif; ?>
            <?= view('shared/attachments.php', ['type' => 'receipt', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'receipt']) ?>
            <?= view('shared/notes.php', ['type' => 'receipt', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'receipt']) ?>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="receipt-applications"><div class="card-header"><h5 class="card-title mb-0">Applied to</h5></div>
                <?php if ($r['applications'] === []): ?><div class="card-body text-muted" id="receipt-applications-empty">Not applied to an invoice.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($r['applications'] as $a): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="receipt-application-<?= $a['invoice_id'] ?>"><span><?= hx_link(with_back('/invoices/' . $a['invoice_id'], $here), e((string) $a['invoice_number'])) ?></span>
                        <span class="d-flex align-items-center gap-2"><span><?= e(fmt_money($a['amount'], $cur)) ?></span>
                        <?php if ($may['write'] && !$void && $r['deposit_id'] === null): ?><form method="post" action="/receipts/unapply.php" hx-post="/receipts/unapply.php" hx-target="#flash" hx-confirm="Take this receipt off the invoice?"><?= csrf_field() ?><input type="hidden" name="receipt" value="<?= $id ?>"><input type="hidden" name="invoice" value="<?= $a['invoice_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light" id="receipt-application-<?= $a['invoice_id'] ?>-unapply-btn">Unapply</button></form><?php endif; ?></span></div>
                <?php endforeach; ?></div><?php endif; ?>
                <?php if ($may['write'] && !$void && (float) $r['unapplied'] > 0 && $open !== []): ?>
                <div class="card-body border-top"><form method="post" action="/receipts/apply.php" hx-post="/receipts/apply.php" hx-target="#flash" class="row g-2 align-items-end" id="receipt-apply-form"><?= csrf_field() ?><input type="hidden" name="receipt" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="receipt-apply-invoice">Apply to</label><select name="invoice" id="receipt-apply-invoice" class="form-select btn-touch"><?php foreach ($open as $i): ?><option value="<?= $i['invoice_id'] ?>"><?= e((string) $i['number']) ?> — <?= e(fmt_money($i['balance_due'], $i['currency'])) ?> owed</option><?php endforeach; ?></select></div>
                    <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="receipt-apply-amount">Amount (empty: what fits)</label><input type="text" inputmode="decimal" name="amount" id="receipt-apply-amount" class="form-control btn-touch"></div>
                    <div class="col-6 col-md-3"><button type="submit" class="btn btn-primary btn-touch w-100" id="receipt-apply-btn">Apply</button></div></form></div>
                <?php endif; ?>
            </div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'receipt']) ?>
        </div>
    </div>
</div>

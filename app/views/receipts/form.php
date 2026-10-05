<?php /** Record a receipt (screen `receipt-add`). Data: customer, customerId, open, currency, amount, claim, inv, prefill, unapplied, banks, pdo, here */ ?>
<?= view('shared/header.php', ['id' => 'receipt-add', 'title' => 'Record a receipt', 'crumbs' => [['Home', '/'], ['Receipts', '/receipts/'], ['Record', null]], 'back' => back_link() ?? ['/receipts/', 'receipts']]) ?>
<div class="main-content" id="receipt-add-content">
<?php if ($customer === null): ?>
    <form method="get" action="/receipts/new" hx-get="/receipts/new" hx-target="#page-content" hx-push-url="true" id="receipt-customer-form" class="card"><div class="card-body">
        <label class="form-label fs-12 text-muted" for="receipt-customer-field">Who paid?</label>
        <div class="mb-3"><?= customer_select($pdo, 'customer', null, 'required', 'Choose a customer…', 'receipt-customer-field') ?></div>
        <button type="submit" class="btn btn-primary btn-touch w-100" id="receipt-customer-btn">Continue</button>
    </div></form>
<?php else: ?>
    <form method="post" action="/receipts/save.php" hx-post="/receipts/save.php" hx-target="#flash" id="receipt-form">
        <?= csrf_field() ?><input type="hidden" name="customer" value="<?= (int) $customerId ?>"><?php if ($claim !== null): ?><input type="hidden" name="claim" value="<?= (int) $claim['receipt_claim_id'] ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">From <?= e($customer['name']) ?></h5><span class="badge bg-soft-secondary text-secondary"><?= e($currency) ?></span></div><div class="card-body">
            <?php if ($claim !== null): ?><div class="alert alert-info fs-12" id="receipt-form-claim">The customer said they paid <?= $claim['amount'] !== null ? e(fmt_money($claim['amount'], $currency)) : 'an amount they did not state' ?> by <?= e($claim['method']) ?> on <?= e(format_date($claim['claimed_on'])) ?><?= $claim['reference'] ? ' (reference ' . e($claim['reference']) . ')' : '' ?>. Confirm the real amount.</div><?php endif; ?>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="receipt-form-field-amount">Amount</label><input type="text" inputmode="decimal" name="amount" id="receipt-form-field-amount" class="form-control btn-touch" required value="<?= e($amount ?? '') ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="receipt-form-field-date">Received on</label><input type="date" name="received_on" id="receipt-form-field-date" class="form-control btn-touch" value="<?= e($claim['claimed_on'] ?? date('Y-m-d')) ?>"></div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="receipt-form-field-method">Method</label><select name="method" id="receipt-form-field-method" class="form-select btn-touch"><?php foreach (RECEIPT_METHODS as $k => $label): ?><option value="<?= e($k) ?>" <?= ($claim['method'] ?? 'transfer') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="receipt-form-field-reference">Reference (cheque number, bank reference)</label><input type="text" name="reference" id="receipt-form-field-reference" class="form-control btn-touch" maxlength="120" value="<?= e($claim['reference'] ?? '') ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="receipt-form-field-deposit">Deposit to</label>
            <select name="deposit_to" id="receipt-form-field-deposit" class="form-select btn-touch mb-3"><option value="">Undeposited funds (a deposit groups it later)</option><?php foreach ($banks as $b): if ($b['currency'] !== $currency) { continue; } ?><option value="<?= $b['bank_account_id'] ?>"><?= e($b['name']) ?> (straight to the bank)</option><?php endforeach; ?></select>
            <label class="form-label fs-12 text-muted" for="receipt-form-field-memo">Memo</label>
            <input type="text" name="memo" id="receipt-form-field-memo" class="form-control btn-touch" maxlength="500">
        </div></div>
        <div class="card mb-3" id="receipt-apply-rows"><div class="card-header"><h5 class="card-title mb-0">Apply it</h5><span class="fs-12 text-muted">Oldest due first; the amounts below are prefilled</span></div>
            <?php if ($open === []): ?><div class="card-body text-muted" id="receipt-apply-empty">This customer has no open invoice. The receipt is recorded unapplied.</div><?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Invoice</th><th>Due</th><th class="text-end">Owed</th><th class="text-end" style="min-width:8rem">Apply</th></tr></thead><tbody>
                <?php foreach ($open as $i): $iid = $i['invoice_id']; $same = $i['currency'] === $currency; ?>
                    <tr id="receipt-apply-<?= $iid ?>"><td><?= hx_link(with_back('/invoices/' . $iid, $here), e((string) $i['number'])) ?></td><td><?= e(format_date($i['due_date'])) ?><?= $i['overdue'] ? ' <span class="badge bg-danger">' . (int) $i['days_overdue'] . ' days</span>' : '' ?></td>
                        <?= fmt_money_cell($i['balance_due'], $i['currency']) ?>
                        <td><?= $same ? '<input type="text" inputmode="decimal" name="apply[' . $iid . ']" class="form-control form-control-sm text-end" value="' . e($prefill[$iid] ?? '') . '" id="receipt-apply-' . $iid . '-amount">' : '<span class="fs-12 text-muted">' . e($i['currency']) . '</span>' ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
            <div class="card-body fs-12 text-muted border-top" id="receipt-apply-left">Left unapplied: <span id="receipt-apply-left-amount"><?= e(fmt_money($unapplied, $currency)) ?></span></div>
        </div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="receipt-form-save-btn">Record the receipt</button>
        <?= hx_link($claim !== null ? '/receipts/claims' : ($inv !== null ? '/invoices/' . (int) $inv['invoice_id'] : '/receipts/'), 'Cancel', 'btn btn-light btn-touch w-100', 'id="receipt-form-cancel-link"') ?>
    </form>
<?php endif; ?>
</div>

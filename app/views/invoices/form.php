<?php /** A new invoice or a draft's head (screens `invoice-add`, `invoice-edit`). Data: cur, copy, customerId, defaults, pdo, items, taxRates, departments, sites, here */
$id = $cur['invoice_id'] ?? null; $screen = $cur === null ? 'invoice-add' : 'invoice-edit'; $back = $cur === null ? ['/invoices/', 'invoices'] : ['/invoices/' . (int) $id, 'the invoice'];
$rows = []; foreach (($copy['lines'] ?? []) as $l) { $rows[] = ['item' => $l['item_id'], 'description' => $l['description'], 'quantity' => rtrim(rtrim($l['quantity'], '0'), '.'), 'unit_price' => $l['unit_price']]; }
while (count($rows) < 3) { $rows[] = ['item' => null, 'description' => '', 'quantity' => '1', 'unit_price' => '']; } ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New invoice' : 'Change ' . ($cur['number'] ?? 'the draft'), 'crumbs' => [['Home', '/'], ['Invoices', '/invoices/'], [$cur === null ? 'New' : ($cur['number'] ?? 'Draft'), $cur === null ? null : '/invoices/' . (int) $id], $cur === null ? ['', null] : ['Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/invoices/save.php" hx-post="/invoices/save.php" hx-target="#flash" id="invoice-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="invoice" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The invoice</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="invoice-form-field-customer">Customer</label>
            <div class="mb-3"><?= customer_select($pdo, 'customer', $customerId === null ? null : (int) $customerId, $cur !== null ? 'required' : 'required', 'Choose a customer…', 'invoice-form-field-customer') ?></div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="invoice-form-field-issue">Issue date</label><input type="date" name="issue_date" id="invoice-form-field-issue" class="form-control btn-touch" value="<?= e($cur['issue_date'] ?? $defaults['issue_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="invoice-form-field-due">Due date (empty: from the terms)</label><input type="date" name="due_date" id="invoice-form-field-due" class="form-control btn-touch" value="<?= e($cur['due_date'] ?? '') ?>"></div>
            </div>
            <?php if ($defaults !== null): ?><div class="fs-12 text-muted mb-3" id="invoice-form-defaults">In <?= e($defaults['currency']) ?>; terms of <?= (int) ((strtotime($defaults['due_date']) - strtotime($defaults['issue_date'])) / 86400) ?> days put the due date at <?= e(format_date($defaults['due_date'])) ?>.</div><?php endif; ?>
            <label class="form-label fs-12 text-muted" for="invoice-form-field-reference">Their reference (PO)</label>
            <input type="text" name="reference" id="invoice-form-field-reference" class="form-control btn-touch mb-3" maxlength="120" value="<?= e($cur['reference'] ?? '') ?>">
            <label class="form-label fs-12 text-muted" for="invoice-form-field-memo">Memo (on the invoice)</label>
            <textarea name="memo" id="invoice-form-field-memo" class="form-control mb-3" rows="2" maxlength="1000"><?= e($cur['memo'] ?? '') ?></textarea>
            <label class="form-label fs-12 text-muted" for="invoice-form-field-terms">Terms text</label>
            <textarea name="terms_text" id="invoice-form-field-terms" class="form-control mb-3" rows="2" maxlength="1000"><?= e($cur['terms_text'] ?? '') ?></textarea>
            <div class="row g-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="invoice-form-field-department">Department</label><select name="department" id="invoice-form-field-department" class="form-select btn-touch"><option value="">None</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= ($cur['department_id'] ?? null) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="invoice-form-field-site">Site</label><select name="site" id="invoice-form-field-site" class="form-select btn-touch"><option value="">None</option><?php foreach ($sites as $s): ?><option value="<?= (int) $s['site_id'] ?>" <?= ($cur['site_id'] ?? null) === (int) $s['site_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            </div>
        </div></div>
        <?php if ($cur === null): ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">First lines</h5><span class="fs-12 text-muted">Taxes, accounts and more lines on the invoice page</span></div>
            <div class="table-responsive"><table class="table table-sm mb-0" id="invoice-form-lines"><thead><tr><th style="min-width:11rem">Item</th><th style="min-width:12rem">Description</th><th style="min-width:5rem" class="text-end">Qty</th><th style="min-width:7rem" class="text-end">Unit price</th></tr></thead><tbody>
                <?php foreach ($rows as $n => $r): ?><tr id="invoice-form-line-<?= $n ?>">
                    <td><select name="line_item[]" class="form-select" form="invoice-form" id="invoice-form-line-<?= $n ?>-item"><option value="">— none —</option><?php foreach ($items as $it): ?><option value="<?= $it['item_id'] ?>" <?= (int) ($r['item'] ?? 0) === $it['item_id'] ? 'selected' : '' ?>><?= e($it['name']) ?> (<?= e(fmt_money($it['unit_price'], 'USD', false)) ?>)</option><?php endforeach; ?></select></td>
                    <td><input type="text" name="line_description[]" class="form-control" maxlength="300" value="<?= e($r['description']) ?>" id="invoice-form-line-<?= $n ?>-description"></td>
                    <td><input type="text" inputmode="decimal" name="line_quantity[]" class="form-control text-end" value="<?= e((string) $r['quantity']) ?>" id="invoice-form-line-<?= $n ?>-quantity"></td>
                    <td><input type="text" inputmode="decimal" name="line_unit_price[]" class="form-control text-end" value="<?= e((string) $r['unit_price']) ?>" id="invoice-form-line-<?= $n ?>-price"></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="card-body fs-12 text-muted">A blank row is skipped. Picking an item fills its description and price, income account and tax rate.</div>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="invoice-form-save-btn"><?= $cur === null ? 'Save the draft' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="invoice-form-cancel-link"') ?>
    </form>
</div>

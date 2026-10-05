<?php /** A new bill or a draft's head (screens `bill-add`, `bill-edit`). Data: cur, copy, vendorId, defaults, pdo, items, taxRates, departments, sites, may, here */
$id = $cur['bill_id'] ?? null; $screen = $cur === null ? 'bill-add' : 'bill-edit'; $back = $cur === null ? ['/bills/', 'bills'] : ['/bills/' . (int) $id, 'the bill'];
$rows = []; foreach (($copy['lines'] ?? []) as $l) { $rows[] = ['item' => $l['item'], 'description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'account' => $l['expense_account']]; }
while (count($rows) < 3) { $rows[] = ['item' => null, 'description' => '', 'quantity' => '1', 'unit_price' => '', 'account' => null]; } ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New bill' : 'Change ' . $cur['number'], 'crumbs' => [['Home', '/'], ['Bills', '/bills/'], [$cur === null ? 'New' : $cur['number'], $cur === null ? null : '/bills/' . (int) $id], $cur === null ? ['', null] : ['Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/bills/save.php" hx-post="/bills/save.php" hx-target="#flash" hx-encoding="multipart/form-data" enctype="multipart/form-data" id="bill-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="bill" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The bill</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="bill-form-field-vendor">Vendor</label>
            <div class="mb-3"><?= vendor_select($pdo, 'vendor', $vendorId === null ? null : (int) $vendorId, 'required', 'Choose a vendor…', 'bill-form-field-vendor') ?></div>
            <label class="form-label fs-12 text-muted" for="bill-form-field-ref">Their invoice number</label>
            <input type="text" name="vendor_ref" id="bill-form-field-ref" class="form-control btn-touch mb-3" maxlength="120" value="<?= e($cur['vendor_ref'] ?? '') ?>">
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bill-form-field-date">Bill date</label><input type="date" name="bill_date" id="bill-form-field-date" class="form-control btn-touch" value="<?= e($cur['bill_date'] ?? $defaults['bill_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bill-form-field-due">Due date (empty: from the terms)</label><input type="date" name="due_date" id="bill-form-field-due" class="form-control btn-touch" value="<?= e($cur['due_date'] ?? '') ?>"></div>
            </div>
            <?php if ($defaults !== null): ?><div class="fs-12 text-muted mb-3" id="bill-form-defaults">In <?= e($defaults['currency']) ?>; their terms of <?= (int) ((strtotime($defaults['due_date']) - strtotime($defaults['bill_date'])) / 86400) ?> days put the due date at <?= e(format_date($defaults['due_date'])) ?>.</div><?php endif; ?>
            <label class="form-label fs-12 text-muted" for="bill-form-field-memo">Memo</label>
            <textarea name="memo" id="bill-form-field-memo" class="form-control mb-3" rows="2" maxlength="1000"><?= e($cur['memo'] ?? $copy['memo'] ?? '') ?></textarea>
            <div class="row g-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bill-form-field-department">Department</label><select name="department" id="bill-form-field-department" class="form-select btn-touch"><option value="">None</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= ($cur['department_id'] ?? $copy['department_id'] ?? null) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bill-form-field-site">Site</label><select name="site" id="bill-form-field-site" class="form-select btn-touch"><option value="">None</option><?php foreach ($sites as $s): ?><option value="<?= (int) $s['site_id'] ?>" <?= ($cur['site_id'] ?? $copy['site_id'] ?? null) === (int) $s['site_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            </div>
        </div></div>
        <?php if ($cur === null): ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">First lines</h5><span class="fs-12 text-muted">Taxes, departments and more lines on the bill page</span></div>
            <div class="table-responsive"><table class="table table-sm mb-0" id="bill-form-lines"><thead><tr><th style="min-width:13rem">Expense account</th><th style="min-width:10rem">Item</th><th style="min-width:12rem">Description</th><th style="min-width:5rem" class="text-end">Qty</th><th style="min-width:7rem" class="text-end">Unit price</th></tr></thead><tbody>
                <?php foreach ($rows as $n => $r): ?><tr id="bill-form-line-<?= $n ?>">
                    <td><?= account_select($pdo, 'line_account[]', $r['account'] === null ? null : (int) $r['account'], ['expense', 'asset'], [], 'form="bill-form"', 'Default', 'bill-form-line-' . $n . '-account') ?></td>
                    <td><select name="line_item[]" class="form-select" form="bill-form" id="bill-form-line-<?= $n ?>-item"><option value="">— none —</option><?php foreach ($items as $it): ?><option value="<?= $it['item_id'] ?>" <?= (int) ($r['item'] ?? 0) === $it['item_id'] ? 'selected' : '' ?>><?= e($it['name']) ?></option><?php endforeach; ?></select></td>
                    <td><input type="text" name="line_description[]" class="form-control" maxlength="300" value="<?= e((string) $r['description']) ?>" id="bill-form-line-<?= $n ?>-description"></td>
                    <td><input type="text" inputmode="decimal" name="line_quantity[]" class="form-control text-end" value="<?= e((string) $r['quantity']) ?>" id="bill-form-line-<?= $n ?>-quantity"></td>
                    <td><input type="text" inputmode="decimal" name="line_unit_price[]" class="form-control text-end" value="<?= e((string) $r['unit_price']) ?>" id="bill-form-line-<?= $n ?>-price"></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="card-body fs-12 text-muted">A blank row is skipped. The expense account is the item's, else the vendor's, else 6900 when left on Default.</div>
        </div>
        <?php if ($may['attach']): ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The document</h5></div><div class="card-body">
            <input type="file" name="document" class="form-control btn-touch" id="bill-form-field-document" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.txt,.csv,.doc,.docx,.xls,.xlsx">
            <div class="fs-12 text-muted mt-1">The vendor's invoice or receipt. A bill without one wears a "no document" chip until you attach it.</div>
        </div></div>
        <?php endif; ?>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="bill-form-save-btn"><?= $cur === null ? 'Save the draft' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="bill-form-cancel-link"') ?>
    </form>
</div>

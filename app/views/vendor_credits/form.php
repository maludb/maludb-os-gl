<?php /** A new vendor credit or a draft's head (screens `vendor-credit-add`, `vendor-credit-edit`). Data: cur, bill, vendorId, prefill, pdo, items, departments, sites */
$id = $cur['vendor_credit_id'] ?? null; $screen = $cur === null ? 'vendor-credit-add' : 'vendor-credit-edit'; $back = $cur === null ? ['/vendor-credits/', 'vendor credits'] : ['/vendor-credits/' . (int) $id, 'the vendor credit'];
$rows = []; foreach ($prefill as $l) { $rows[] = ['description' => $l['description'], 'quantity' => $l['quantity'], 'unit_price' => $l['unit_price'], 'account' => $l['expense_account']]; }
while (count($rows) < 3) { $rows[] = ['description' => '', 'quantity' => '1', 'unit_price' => '', 'account' => null]; } ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New vendor credit' : 'Change the vendor credit', 'crumbs' => [['Home', '/'], ['Vendor credits', '/vendor-credits/'], [$cur === null ? 'New' : $cur['number'], null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/vendor-credits/save.php" hx-post="/vendor-credits/save.php" hx-target="#flash" id="vendor-credit-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="vendor_credit" value="<?= (int) $id ?>"><?php endif; ?><?php if ($bill !== null): ?><input type="hidden" name="bill" value="<?= (int) $bill['bill_id'] ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The vendor credit</h5><?php if ($bill !== null): ?><span class="fs-12 text-muted" id="vendor-credit-form-against">Against <?= e($bill['number']) ?></span><?php endif; ?></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="vendor-credit-form-field-vendor">Vendor</label>
            <div class="mb-3"><?= vendor_select($pdo, 'vendor', $vendorId === null ? null : (int) $vendorId, 'required', 'Choose a vendor…', 'vendor-credit-form-field-vendor') ?></div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="vendor-credit-form-field-date">Date</label><input type="date" name="credit_date" id="vendor-credit-form-field-date" class="form-control btn-touch" value="<?= e($cur['credit_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="vendor-credit-form-field-ref">Their credit number</label><input type="text" name="vendor_ref" id="vendor-credit-form-field-ref" class="form-control btn-touch" maxlength="120" value="<?= e($cur['vendor_ref'] ?? '') ?>"></div>
            </div>
            <label class="form-label fs-12 text-muted" for="vendor-credit-form-field-reason">Reason</label>
            <input type="text" name="reason" id="vendor-credit-form-field-reason" class="form-control btn-touch mb-3" maxlength="500" value="<?= e($cur['reason'] ?? '') ?>">
            <div class="row g-2"><div class="col-6"><label class="form-label fs-12 text-muted" for="vendor-credit-form-field-department">Department</label><select name="department" id="vendor-credit-form-field-department" class="form-select btn-touch"><option value="">None</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= ($cur['department_id'] ?? $bill['department_id'] ?? null) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="vendor-credit-form-field-site">Site</label><select name="site" id="vendor-credit-form-field-site" class="form-select btn-touch"><option value="">None</option><?php foreach ($sites as $s): ?><option value="<?= (int) $s['site_id'] ?>" <?= ($cur['site_id'] ?? $bill['site_id'] ?? null) === (int) $s['site_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div></div>
        </div></div>
        <?php if ($cur === null): ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">First lines</h5><span class="fs-12 text-muted"><?= $bill !== null ? 'Prefilled from the bill' : 'Taxes and more on the vendor credit page' ?></span></div>
            <div class="table-responsive"><table class="table table-sm mb-0" id="vendor-credit-form-lines"><thead><tr><th style="min-width:13rem">Expense account</th><th style="min-width:12rem">Description</th><th class="text-end" style="min-width:5rem">Qty</th><th class="text-end" style="min-width:7rem">Unit price</th></tr></thead><tbody>
                <?php foreach ($rows as $n => $r): ?><tr id="vendor-credit-form-line-<?= $n ?>">
                    <td><?= account_select($pdo, 'line_account[]', $r['account'] === null ? null : (int) $r['account'], ['expense', 'asset'], [], 'form="vendor-credit-form"', 'Default', 'vendor-credit-form-line-' . $n . '-account') ?></td>
                    <td><input type="text" name="line_description[]" class="form-control" maxlength="300" value="<?= e((string) $r['description']) ?>" id="vendor-credit-form-line-<?= $n ?>-description"></td>
                    <td><input type="text" inputmode="decimal" name="line_quantity[]" class="form-control text-end" value="<?= e((string) $r['quantity']) ?>" id="vendor-credit-form-line-<?= $n ?>-quantity"></td>
                    <td><input type="text" inputmode="decimal" name="line_unit_price[]" class="form-control text-end" value="<?= e((string) $r['unit_price']) ?>" id="vendor-credit-form-line-<?= $n ?>-price"></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="vendor-credit-form-save-btn"><?= $cur === null ? 'Save the draft' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="vendor-credit-form-cancel-link"') ?>
    </form>
</div>

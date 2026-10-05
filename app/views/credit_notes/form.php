<?php /** A new credit note or a draft's head (screens `credit-note-add`, `credit-note-edit`). Data: cur, inv, customerId, prefill, pdo, departments, sites */
$id = $cur['credit_note_id'] ?? null; $screen = $cur === null ? 'credit-note-add' : 'credit-note-edit'; $back = $cur === null ? ['/credit-notes/', 'credit notes'] : ['/credit-notes/' . (int) $id, 'the credit note'];
$rows = $prefill; while (count($rows) < 3) { $rows[] = ['item' => null, 'description' => '', 'quantity' => '1', 'unit_price' => '']; } ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New credit note' : 'Change the credit note', 'crumbs' => [['Home', '/'], ['Credit notes', '/credit-notes/'], [$cur === null ? 'New' : ($cur['number'] ?? 'Draft'), null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/credit-notes/save.php" hx-post="/credit-notes/save.php" hx-target="#flash" id="credit-note-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="credit_note" value="<?= (int) $id ?>"><?php endif; ?><?php if ($inv !== null): ?><input type="hidden" name="invoice" value="<?= (int) $inv['invoice_id'] ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The credit note</h5><?php if ($inv !== null): ?><span class="fs-12 text-muted" id="credit-note-form-against">Against <?= e((string) $inv['number']) ?></span><?php endif; ?></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="credit-note-form-field-customer">Customer</label>
            <div class="mb-3"><?= customer_select($pdo, 'customer', $customerId === null ? null : (int) $customerId, 'required', 'Choose a customer…', 'credit-note-form-field-customer') ?></div>
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="credit-note-form-field-date">Date</label><input type="date" name="issue_date" id="credit-note-form-field-date" class="form-control btn-touch" value="<?= e($cur['issue_date'] ?? date('Y-m-d')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="credit-note-form-field-reason">Reason</label><input type="text" name="reason" id="credit-note-form-field-reason" class="form-control btn-touch" maxlength="500" value="<?= e($cur['reason'] ?? '') ?>"></div>
            </div>
            <div class="row g-2"><div class="col-6"><label class="form-label fs-12 text-muted" for="credit-note-form-field-department">Department</label><select name="department" id="credit-note-form-field-department" class="form-select btn-touch"><option value="">None</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= ($cur['department_id'] ?? $inv['department_id'] ?? null) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="credit-note-form-field-site">Site</label><select name="site" id="credit-note-form-field-site" class="form-select btn-touch"><option value="">None</option><?php foreach ($sites as $s): ?><option value="<?= (int) $s['site_id'] ?>" <?= ($cur['site_id'] ?? $inv['site_id'] ?? null) === (int) $s['site_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div></div>
        </div></div>
        <?php if ($cur === null): ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">First lines</h5><span class="fs-12 text-muted"><?= $inv !== null ? 'Prefilled from the invoice' : 'Accounts and taxes on the credit note page' ?></span></div>
            <div class="table-responsive"><table class="table table-sm mb-0" id="credit-note-form-lines"><thead><tr><th style="min-width:12rem">Description</th><th class="text-end" style="min-width:5rem">Qty</th><th class="text-end" style="min-width:7rem">Unit price</th></tr></thead><tbody>
                <?php foreach ($rows as $n => $r): ?><tr id="credit-note-form-line-<?= $n ?>"><td><input type="text" name="line_description[]" class="form-control" maxlength="300" value="<?= e((string) $r['description']) ?>" id="credit-note-form-line-<?= $n ?>-description"></td>
                    <td><input type="text" inputmode="decimal" name="line_quantity[]" class="form-control text-end" value="<?= e((string) $r['quantity']) ?>" id="credit-note-form-line-<?= $n ?>-quantity"></td><td><input type="text" inputmode="decimal" name="line_unit_price[]" class="form-control text-end" value="<?= e((string) $r['unit_price']) ?>" id="credit-note-form-line-<?= $n ?>-price"></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="credit-note-form-save-btn"><?= $cur === null ? 'Save the draft' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="credit-note-form-cancel-link"') ?>
    </form>
</div>

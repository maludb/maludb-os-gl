<?php /** Add or change a recurring template (screens `recurring-add`, `recurring-edit`). Data: cur, prefill, kind, pdo, departments, sites, here */
$id = $cur['recurring_template_id'] ?? null; $screen = $cur === null ? 'recurring-add' : 'recurring-edit';
$src = $cur ?? $prefill ?? []; $tpl = $src['template'] ?? []; $lines = $tpl['lines'] ?? []; $lines[] = []; while (count($lines) < 3) { $lines[] = []; }
$val = static fn (string $k, mixed $d = '') => $cur[$k] ?? $d; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New recurring ' . strtolower(TEMPLATE_KINDS[$kind]) : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Settings', '/settings/'], ['Recurring', '/settings/recurring/'], [$cur === null ? 'New' : $cur['name'], null]], 'back' => back_link() ?? ['/settings/recurring/', 'the recurring templates']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/recurring/save.php" hx-post="/settings/recurring/save.php" hx-target="#flash" id="recurring-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="recurring_template" value="<?= (int) $id ?>"><?php endif; ?><input type="hidden" name="kind" value="<?= e($kind) ?>">
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The schedule</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="recurring-form-field-name">Name</label>
            <input type="text" name="name" id="recurring-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($val('name', $prefill['name'] ?? '')) ?>">
            <div class="row g-2 mb-3">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="recurring-form-field-cadence">Repeats</label><select name="cadence" id="recurring-form-field-cadence" class="form-select btn-touch"><?php foreach (CADENCES as $k => $l): ?><option value="<?= e($k) ?>" <?= $val('cadence', 'monthly') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="recurring-form-field-every">Every N</label><input type="number" min="1" max="24" name="every_n" id="recurring-form-field-every" class="form-control btn-touch" value="<?= (int) $val('every_n', 1) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="recurring-form-field-next">Next date</label><input type="date" name="next_date" id="recurring-form-field-next" class="form-control btn-touch" required value="<?= e($val('next_date', date('Y-m-d'))) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="recurring-form-field-end">Ends (optional)</label><input type="date" name="end_date" id="recurring-form-field-end" class="form-control btn-touch" value="<?= e((string) $val('end_date', '')) ?>"></div>
            </div>
            <input type="hidden" name="active" value="no"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" value="yes" id="recurring-form-field-active" <?= $val('active', true) ? 'checked' : '' ?>><label class="form-check-label" for="recurring-form-field-active">On</label></div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0"><?= e(TEMPLATE_KINDS[$kind]) ?> to draft</h5></div><div class="card-body">
            <?php if ($kind === 'bill'): ?>
                <label class="form-label fs-12 text-muted" for="recurring-form-field-vendor">Vendor</label><div class="mb-2"><?= vendor_select($pdo, 'vendor', isset($tpl['vendor']) ? (int) $tpl['vendor'] : null, 'required', 'Choose a vendor…', 'recurring-form-field-vendor') ?></div>
                <label class="form-label fs-12 text-muted" for="recurring-form-field-vref">Vendor reference pattern (e.g. RENT-{YYYY-MM})</label><input type="text" name="vendor_ref_pattern" id="recurring-form-field-vref" class="form-control btn-touch mb-2" value="<?= e((string) ($tpl['vendor_ref_pattern'] ?? '')) ?>">
            <?php elseif ($kind === 'invoice'): ?>
                <label class="form-label fs-12 text-muted" for="recurring-form-field-customer">Customer</label><div class="mb-2"><?= customer_select($pdo, 'customer', isset($tpl['customer']) ? (int) $tpl['customer'] : null, 'required', 'Choose a customer…', 'recurring-form-field-customer') ?></div>
                <label class="form-label fs-12 text-muted" for="recurring-form-field-ref">Reference pattern (e.g. Retainer {MON} {YYYY})</label><input type="text" name="reference_pattern" id="recurring-form-field-ref" class="form-control btn-touch mb-2" value="<?= e((string) ($tpl['reference_pattern'] ?? '')) ?>">
            <?php else: ?>
                <label class="form-label fs-12 text-muted" for="recurring-form-field-offset">Day offset from the run date (0: the run date itself)</label><input type="number" name="day_offset" id="recurring-form-field-offset" min="-31" max="31" class="form-control btn-touch mb-2" value="<?= (int) ($tpl['day_offset'] ?? 0) ?>">
            <?php endif; ?>
            <label class="form-label fs-12 text-muted" for="recurring-form-field-memo">Memo</label><input type="text" name="memo" id="recurring-form-field-memo" class="form-control btn-touch mb-3" maxlength="1000" value="<?= e((string) ($tpl['memo'] ?? '')) ?>">
            <div class="fs-12 text-muted mb-1">Lines — leave a row empty to skip it<?= $kind === 'journal' ? '; debits must equal credits' : '' ?>.</div>
            <?php foreach ($lines as $i => $l): $n = $i + 1; $acc = $l['account'] ?? $l['expense_account'] ?? $l['income_account'] ?? null; ?>
                <div class="border rounded p-2 mb-2" id="recurring-line-<?= $n ?>"><div class="row g-2">
                    <div class="col-12 col-md-5"><?= account_select($pdo, 'line_account[]', $acc === null ? null : (int) $acc, $kind === 'journal' ? [] : [$kind === 'bill' ? 'expense' : 'income'], [], '', 'Account…', 'recurring-line-' . $n . '-account') ?></div>
                    <div class="col-12 col-md-7"><input type="text" name="line_description[]" class="form-control btn-touch" placeholder="Description" value="<?= e((string) ($l['description'] ?? '')) ?>" id="recurring-line-<?= $n ?>-description"></div>
                    <?php if ($kind === 'journal'): ?>
                        <div class="col-6"><input type="text" inputmode="decimal" name="line_debit[]" class="form-control btn-touch" placeholder="Debit" value="<?= e(isset($l['debit']) && (float) $l['debit'] > 0 ? (string) $l['debit'] : '') ?>" id="recurring-line-<?= $n ?>-debit"></div>
                        <div class="col-6"><input type="text" inputmode="decimal" name="line_credit[]" class="form-control btn-touch" placeholder="Credit" value="<?= e(isset($l['credit']) && (float) $l['credit'] > 0 ? (string) $l['credit'] : '') ?>" id="recurring-line-<?= $n ?>-credit"></div>
                    <?php else: ?>
                        <div class="col-6"><input type="text" inputmode="decimal" name="line_quantity[]" class="form-control btn-touch" placeholder="Quantity" value="<?= e((string) ($l['quantity'] ?? '1')) ?>" id="recurring-line-<?= $n ?>-quantity"></div>
                        <div class="col-6"><input type="text" inputmode="decimal" name="line_unit_price[]" class="form-control btn-touch" placeholder="Unit price" value="<?= e((string) ($l['unit_price'] ?? '')) ?>" id="recurring-line-<?= $n ?>-price"></div>
                    <?php endif; ?>
                </div></div>
            <?php endforeach; ?>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="recurring-form-save-btn">Save</button>
        <?= hx_link($id === null ? '/settings/recurring/' : '/settings/recurring/' . $id, 'Cancel', 'btn btn-light btn-touch w-100', 'id="recurring-form-cancel-link"') ?>
    </form>
</div>

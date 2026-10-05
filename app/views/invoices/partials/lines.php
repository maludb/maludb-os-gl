<?php /** The lines region of an invoice or a credit note (#invoice-lines / #credit-note-lines): the editor for a draft, a read-only table otherwise. The inputs belong to forms rendered after the table (form="…"), never a <form> in a <tr>.
 * Data: kind, doc, id, lines, editable, pdo, items, taxRates, departments, sites, currency, prefix, side (income|expense), saveUrl, removeUrl, param */ $p = $prefix; $exp = ($side ?? 'income') === 'expense'; $lk = $kind . '_line_id'; ?>
<div id="<?= e($p) ?>-lines" class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Lines</h5></div>
    <div class="table-responsive"><table class="table table-sm mb-0" id="<?= e($p) ?>-lines-table">
        <thead><tr><th>#</th><th style="min-width:10rem">Item</th><th style="min-width:12rem">Description</th><th class="text-end" style="min-width:5rem">Qty</th><th class="text-end" style="min-width:7rem">Unit price</th><th style="min-width:8rem">Tax</th><th style="min-width:13rem"><?= $exp ? 'Expense account' : 'Income account' ?></th><th style="min-width:7rem">Department</th><th style="min-width:6rem">Site</th><th class="text-end" style="min-width:7rem">Total</th><?= $editable ? '<th></th>' : '' ?></tr></thead>
        <tbody>
            <?php if ($lines === [] && !$editable): ?><tr id="<?= e($p) ?>-lines-empty"><td colspan="10" class="text-center text-muted py-3">No lines.</td></tr><?php endif; ?>
            <?php foreach ($lines as $l): ?>
                <?php if ($editable): ?><?= view('invoices/partials/line-row.php', ['l' => $l, 'doc' => $doc, 'prefix' => $p, 'pdo' => $pdo, 'items' => $items, 'taxRates' => $taxRates, 'departments' => $departments, 'sites' => $sites, 'currency' => $currency, 'side' => $side ?? 'income', 'kind' => $kind]) ?>
                <?php else: $lid = (int) $l[$lk]; ?>
                    <tr id="<?= e($p) ?>-line-<?= $lid ?>"><td class="text-muted fs-12"><?= (int) $l['sort_order'] ?></td><td class="fs-12"><?= e($l['item_name'] ?? '') ?></td><td><?= e($l['description']) ?></td>
                        <td class="text-end"><?= e(rtrim(rtrim((string) $l['quantity'], '0'), '.')) ?></td><?= fmt_money_cell($l['unit_price'], $currency) ?><td class="fs-12"><?= (float) $l['tax_rate'] > 0 ? e(rtrim(rtrim((string) $l['tax_rate'], '0'), '.')) . '%' : '' ?></td>
                        <td class="fs-12"><?= e((string) ($l[$exp ? 'expense_account_code' : 'income_account_code'] ?? '')) ?></td><td class="fs-12"></td><td class="fs-12"></td><?= fmt_money_cell($l['line_total'], $currency) ?></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($editable): ?><?= view('invoices/partials/line-row.php', ['l' => null, 'doc' => $doc, 'prefix' => $p, 'pdo' => $pdo, 'items' => $items, 'taxRates' => $taxRates, 'departments' => $departments, 'sites' => $sites, 'currency' => $currency, 'side' => $side ?? 'income', 'kind' => $kind]) ?><?php endif; ?>
        </tbody>
    </table></div>
    <?php if ($editable): ?>
        <?php foreach ($lines as $l): $lid = (int) $l[$lk]; ?>
            <form method="post" action="<?= e($saveUrl) ?>" hx-post="<?= e($saveUrl) ?>" hx-target="#<?= e($p) ?>-lines" hx-swap="outerHTML" id="<?= e($p) ?>-line-form-<?= $lid ?>"><?= csrf_field() ?><input type="hidden" name="<?= e($param) ?>" value="<?= (int) $id ?>"><input type="hidden" name="line" value="<?= $lid ?>"></form>
            <form method="post" action="<?= e($removeUrl) ?>" hx-post="<?= e($removeUrl) ?>" hx-target="#<?= e($p) ?>-lines" hx-swap="outerHTML" id="<?= e($p) ?>-line-remove-<?= $lid ?>"><?= csrf_field() ?><input type="hidden" name="<?= e($param) ?>" value="<?= (int) $id ?>"><input type="hidden" name="line" value="<?= $lid ?>"></form>
        <?php endforeach; ?>
        <form method="post" action="<?= e($saveUrl) ?>" hx-post="<?= e($saveUrl) ?>" hx-target="#<?= e($p) ?>-lines" hx-swap="outerHTML" id="<?= e($p) ?>-line-form-new"><?= csrf_field() ?><input type="hidden" name="<?= e($param) ?>" value="<?= (int) $id ?>"></form>
    <?php endif; ?>
</div>

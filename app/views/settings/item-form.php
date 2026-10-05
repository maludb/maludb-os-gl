<?php /** An item (screens `item-add`, `item-edit`). Data: cur, pdo, taxRates */ $id = $cur['item_id'] ?? null; $screen = $cur === null ? 'item-add' : 'item-edit'; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add an item' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Items', '/settings/items/'], [$cur === null ? 'Add' : $cur['name'], null]], 'back' => back_link() ?? ['/settings/items/', 'Items']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/items/save.php" hx-post="/settings/items/save.php" hx-target="#flash" id="item-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="item" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The item</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-8"><label class="form-label fs-12 text-muted" for="item-form-field-name">Name</label><input type="text" name="name" id="item-form-field-name" class="form-control btn-touch" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>" placeholder="Consulting hour"></div>
                <div class="col-4"><label class="form-label fs-12 text-muted" for="item-form-field-code">Code</label><input type="text" name="code" id="item-form-field-code" class="form-control btn-touch" maxlength="40" value="<?= e($cur['code'] ?? '') ?>" placeholder="CONS-H"></div>
            </div>
            <label class="form-label fs-12 text-muted">Kind</label>
            <div class="row g-2 mb-3"><?php foreach (ITEM_KINDS as $k => $label): ?><div class="col-6"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="item-form-field-kind-<?= e($k) ?>"><input type="radio" class="form-check-input mt-0" name="kind" value="<?= e($k) ?>" id="item-form-field-kind-<?= e($k) ?>" <?= ($cur['kind'] ?? 'service') === $k ? 'checked' : '' ?>><?= e($label) ?></label></div><?php endforeach; ?></div>
            <label class="form-label fs-12 text-muted" for="item-form-field-description">Description (on the invoice line)</label>
            <textarea name="description" id="item-form-field-description" class="form-control mb-3" rows="2" maxlength="1000"><?= e($cur['description'] ?? '') ?></textarea>
            <div class="row g-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="item-form-field-price">Unit price</label><input type="text" inputmode="decimal" name="unit_price" id="item-form-field-price" class="form-control btn-touch" value="<?= e($cur['unit_price'] ?? '0.00') ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="item-form-field-unit">Unit</label><input type="text" name="unit" id="item-form-field-unit" class="form-control btn-touch" maxlength="20" value="<?= e($cur['unit'] ?? '') ?>" placeholder="hour, each, month"></div>
            </div>
        </div></div>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Where it posts</h5></div><div class="card-body">
            <label class="form-label fs-12 text-muted" for="item-form-field-income">Income account (when sold)</label>
            <div class="mb-3"><?= account_select($pdo, 'income_account', $cur['income_account_id'] ?? null, ['income'], [], '', 'None', 'item-form-field-income') ?></div>
            <label class="form-label fs-12 text-muted" for="item-form-field-expense">Expense account (when bought)</label>
            <div class="mb-3"><?= account_select($pdo, 'expense_account', $cur['expense_account_id'] ?? null, ['expense'], [], '', 'None', 'item-form-field-expense') ?></div>
            <label class="form-label fs-12 text-muted" for="item-form-field-tax">Tax rate</label>
            <select name="tax_rate" id="item-form-field-tax" class="form-select btn-touch"><option value="">None</option><?php foreach ($taxRates as $t): ?><option value="<?= (int) $t['tax_rate_id'] ?>" <?= ($cur['tax_rate_id'] ?? null) === (int) $t['tax_rate_id'] ? 'selected' : '' ?>><?= e($t['name']) ?> (<?= e(rtrim(rtrim(number_format((float) $t['rate'], 4, '.', ''), '0'), '.')) ?>%)</option><?php endforeach; ?></select>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="item-form-save-btn">Save</button>
        <?= hx_link('/settings/items/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="item-form-cancel-link"') ?>
    </form>
</div>

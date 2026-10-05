<?php /** A tax rate (screens `tax-rate-add`, `tax-rate-edit`). Data: cur */ $id = $cur['tax_rate_id'] ?? null; $screen = $cur === null ? 'tax-rate-add' : 'tax-rate-edit'; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a tax rate' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Tax rates', '/settings/tax-rates/'], [$cur === null ? 'Add' : $cur['name'], null]], 'back' => back_link() ?? ['/settings/tax-rates/', 'Tax rates']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/tax-rates/save.php" hx-post="/settings/tax-rates/save.php" hx-target="#flash" id="tax-rate-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="tax_rate" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="tax-rate-form-field-name">Name</label>
            <input type="text" name="name" id="tax-rate-form-field-name" class="form-control btn-touch mb-3" maxlength="60" required value="<?= e($cur['name'] ?? '') ?>" placeholder="State sales tax">
            <label class="form-label fs-12 text-muted" for="tax-rate-form-field-rate">Rate (percent)</label>
            <input type="number" name="rate" id="tax-rate-form-field-rate" class="form-control btn-touch mb-3" min="0" max="99.9999" step="0.0001" required value="<?= e($cur === null ? '' : rtrim(rtrim(number_format((float) $cur['rate'], 4, '.', ''), '0'), '.')) ?>" placeholder="8.25">
            <input type="hidden" name="is_default" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="tax-rate-form-field-default"><input type="checkbox" class="form-check-input mt-0" name="is_default" value="yes" id="tax-rate-form-field-default" <?= ($cur['is_default'] ?? false) ? 'checked' : '' ?>>The default rate on a new line</label>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="tax-rate-form-save-btn">Save</button>
        <?= hx_link('/settings/tax-rates/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="tax-rate-form-cancel-link"') ?>
    </form>
</div>

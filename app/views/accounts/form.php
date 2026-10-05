<?php /** Add or change an account (screens `account-add`, `account-edit`). Data: cur, type, parent, parents, here */
$id = $cur['account_id'] ?? null; $screen = $cur === null ? 'account-add' : 'account-edit'; $system = (bool) ($cur['is_system'] ?? false);
$back = $cur === null ? ['/accounts/', 'the chart'] : ['/accounts/' . (int) $id, 'the account']; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add an account' : 'Change ' . $cur['code'] . ' ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Chart of accounts', '/accounts/'], [$cur === null ? 'Add' : $cur['code'], $cur === null ? null : '/accounts/' . (int) $id], $cur === null ? ['', null] : ['Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/accounts/save.php" hx-post="/accounts/save.php" hx-target="#flash" id="account-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="account" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The account</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-4"><label class="form-label fs-12 text-muted" for="account-form-field-code">Code<?= $system ? ' (fixed)' : '' ?></label>
                    <input type="text" name="code" id="account-form-field-code" class="form-control btn-touch" maxlength="13" required pattern="[0-9]{3,8}(\.[0-9]{1,4})?" value="<?= e($cur['code'] ?? '') ?>" placeholder="6150" <?= $system ? 'readonly' : '' ?>></div>
                <div class="col-8"><label class="form-label fs-12 text-muted" for="account-form-field-name">Name</label>
                    <input type="text" name="name" id="account-form-field-name" class="form-control btn-touch" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>" placeholder="Software subscriptions"></div>
            </div>
            <label class="form-label fs-12 text-muted">Type<?= $system ? ' (a system account keeps its type)' : '' ?></label>
            <div class="row g-2 mb-3" id="account-form-types">
                <?php foreach (ACCOUNT_TYPES as $t => $label): ?>
                    <div class="col-6 col-md-4"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="account-form-field-type-<?= e($t) ?>"><input type="radio" class="form-check-input mt-0" name="account_type" value="<?= e($t) ?>" id="account-form-field-type-<?= e($t) ?>" <?= $type === $t ? 'checked' : '' ?> <?= $system ? 'disabled' : '' ?> onchange="glSubtypes(this.value)"><?= e($label) ?></label></div>
                <?php endforeach; ?>
                <?php if ($system): ?><input type="hidden" name="account_type" value="<?= e($type) ?>"><?php endif; ?>
            </div>
            <label class="form-label fs-12 text-muted" for="account-form-field-subtype">Subtype</label>
            <select name="subtype" id="account-form-field-subtype" class="form-select btn-touch mb-3" data-subtypes="<?= e(json_encode(ACCOUNT_SUBTYPES)) ?>">
                <?php foreach (ACCOUNT_SUBTYPES[$type] as $k => $label): ?><option value="<?= e($k) ?>" <?= ($cur['subtype'] ?? 'other') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
            </select>
            <label class="form-label fs-12 text-muted" for="account-form-field-parent">Parent (an account of the same type)</label>
            <select name="parent" id="account-form-field-parent" class="form-select btn-touch mb-3">
                <option value="">None</option>
                <?php foreach ($parents as $p): ?><option value="<?= (int) $p['account_id'] ?>" data-type="<?= e($p['account_type']) ?>" <?= $parent === (int) $p['account_id'] ? 'selected' : '' ?>><?= e($p['code'] . ' — ' . $p['name']) ?></option><?php endforeach; ?>
            </select>
            <input type="hidden" name="is_bank" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 mb-3 btn-touch" for="account-form-field-bank"><input type="checkbox" class="form-check-input mt-0" name="is_bank" value="yes" id="account-form-field-bank" <?= ($cur['is_bank'] ?? false) ? 'checked' : '' ?>>A bank account (never a control account)</label>
            <?php if ($cur !== null && $cur['is_control']): ?><div class="fs-12 text-muted mb-3" id="account-form-control-note">This is a control account: every line on it names its customer or vendor.</div><?php endif; ?>
            <label class="form-label fs-12 text-muted" for="account-form-field-description">Description</label>
            <textarea name="description" id="account-form-field-description" class="form-control" rows="2" maxlength="500"><?= e($cur['description'] ?? '') ?></textarea>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="account-form-save-btn">Save</button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="account-form-cancel-link"') ?>
    </form>
    <script>
    function glSubtypes(t) {
        var s = document.getElementById('account-form-field-subtype'), all = JSON.parse(s.dataset.subtypes), cur = s.value; s.innerHTML = '';
        Object.keys(all[t] || {}).forEach(function (k) { var o = document.createElement('option'); o.value = k; o.textContent = all[t][k]; if (k === cur) { o.selected = true; } s.appendChild(o); });
        var p = document.getElementById('account-form-field-parent'); Array.prototype.forEach.call(p.options, function (o) { o.hidden = o.value !== '' && o.dataset.type !== t; if (o.hidden && o.selected) { p.value = ''; } });
    }
    (function () { var c = document.querySelector('#account-form-types input:checked'); if (c) { glSubtypes(c.value); } })();
    </script>
</div>

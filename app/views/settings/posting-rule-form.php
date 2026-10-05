<?php /** A rule's accounts (screen `posting-rule-edit`). Data: cur, source, pdo */ $id = $cur['posting_rule_id'] ?? null; $usual = POSTING_USUAL[$source] ?? [null, null]; ?>
<?= view('shared/header.php', ['id' => 'posting-rule-edit', 'title' => $cur === null ? 'Add a posting rule' : 'Change the rule: ' . $cur['source_label'], 'crumbs' => [['Home', '/'], ['Posting rules', '/settings/posting-rules/'], [$cur === null ? 'Add' : $cur['source_label'], null]], 'back' => back_link() ?? ['/settings/posting-rules/', 'Posting rules']]) ?>
<div class="main-content" id="posting-rule-edit-content">
    <form method="post" action="/settings/posting-rules/save.php" hx-post="/settings/posting-rules/save.php" hx-target="#flash" id="posting-rule-form">
        <?= csrf_field() ?>
        <div class="card mb-3"><div class="card-body">
            <?php if ($cur !== null): ?>
                <input type="hidden" name="rule" value="<?= (int) $id ?>">
                <dl class="row fs-12 mb-3"><dt class="col-4 text-muted">Source</dt><dd class="col-8"><?= e($cur['source_label']) ?></dd><dt class="col-4 text-muted">Key</dt><dd class="col-8"><?= $cur['match_key'] === null ? 'default' : '<code>' . e($cur['match_key']) . '</code>' ?></dd></dl>
            <?php else: ?>
                <label class="form-label fs-12 text-muted" for="posting-rule-form-field-source">Source</label>
                <select name="rule" id="posting-rule-form-field-source" class="form-select btn-touch mb-3"><?php foreach (POSTING_SOURCES as $k => $label): ?><option value="<?= e($k) ?>" <?= $source === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select>
                <label class="form-label fs-12 text-muted" for="posting-rule-form-field-key">Key (an item or vendor key; empty = the source's default rule)</label>
                <input type="text" name="match_key" id="posting-rule-form-field-key" class="form-control btn-touch mb-3" maxlength="60" placeholder="hosting">
            <?php endif; ?>
            <label class="form-label fs-12 text-muted" for="posting-rule-form-field-debit">Debit account<?= $usual[0] !== null ? ' <span class="fw-normal">(usually ' . e($usual[0]) . ')</span>' : '' ?></label>
            <div class="mb-3"><?= account_select($pdo, 'debit_account', $cur['debit_account_id'] ?? null, [], [], '', 'None', 'posting-rule-form-field-debit') ?></div>
            <label class="form-label fs-12 text-muted" for="posting-rule-form-field-credit">Credit account<?= $usual[1] !== null ? ' <span class="fw-normal">(usually ' . e($usual[1]) . ')</span>' : '' ?></label>
            <div class="mb-3"><?= account_select($pdo, 'credit_account', $cur['credit_account_id'] ?? null, [], [], '', 'None', 'posting-rule-form-field-credit') ?></div>
            <label class="form-label fs-12 text-muted" for="posting-rule-form-field-tax">Tax account (liability; for a document with tax)</label>
            <div class="mb-3"><?= account_select($pdo, 'tax_account', $cur['tax_account_id'] ?? null, [], [], '', 'None', 'posting-rule-form-field-tax') ?></div>
            <div class="fs-12 text-muted mb-3" id="posting-rule-form-usual-note">Any account can be chosen; an unusual type is allowed and simply noted here.</div>
            <label class="form-label fs-12 text-muted" for="posting-rule-form-field-note">Note</label>
            <input type="text" name="note" id="posting-rule-form-field-note" class="form-control btn-touch mb-3" maxlength="300" value="<?= e($cur['note'] ?? '') ?>">
            <input type="hidden" name="active" value="no">
            <label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch" for="posting-rule-form-field-active"><input type="checkbox" class="form-check-input mt-0" name="active" value="yes" id="posting-rule-form-field-active" <?= ($cur['active'] ?? true) ? 'checked' : '' ?>>Active</label>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="posting-rule-form-save-btn">Save</button>
        <?= hx_link('/settings/posting-rules/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="posting-rule-form-cancel-link"') ?>
    </form>
</div>

<?php /** Which source posts where (screen `posting-rules`, a table). Data: rows, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'posting-rules', 'title' => 'Posting rules', 'crumbs' => [['Home', '/'], ['Ledger settings', '/settings/general'], ['Posting rules', null]],
    'action' => hx_link('/settings/posting-rules/new', '<i class="feather-plus me-1"></i>Add a keyed rule', 'btn btn-primary btn-touch', 'id="posting-rules-add-btn"')]) ?>
<div class="main-content" id="posting-rules-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2">A document posts by its source's rule; a keyed rule (an item or vendor key) wins over the default. The handler that posts asks <code>gl_posting_rule()</code>.</div>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0" id="posting-rules-table">
        <thead><tr><th>Source</th><th>Key</th><th>Debit</th><th>Credit</th><th>Tax</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($rows as $r): $id = (int) $r['posting_rule_id']; ?>
                <tr id="posting-rule-row-<?= $id ?>"<?= $r['active'] ? '' : ' class="text-muted"' ?>>
                    <td class="fw-semibold"><?= e($r['source_label']) ?><?= $r['active'] ? '' : ' <span class="badge bg-soft-secondary text-secondary">Off</span>' ?><?= $r['note'] ? '<div class="fs-12 text-muted fw-normal">' . e($r['note']) . '</div>' : '' ?></td>
                    <td><?= $r['match_key'] === null ? '<span class="text-muted">default</span>' : '<code>' . e($r['match_key']) . '</code>' ?></td>
                    <td id="posting-rule-row-<?= $id ?>-debit"><?= $r['debit_account_id'] === null ? '—' : e($r['debit_account_code'] . ' ' . $r['debit_account_name']) ?></td>
                    <td id="posting-rule-row-<?= $id ?>-credit"><?= $r['credit_account_id'] === null ? '—' : e($r['credit_account_code'] . ' ' . $r['credit_account_name']) ?></td>
                    <td id="posting-rule-row-<?= $id ?>-tax"><?= $r['tax_account_id'] === null ? '—' : e($r['tax_account_code']) ?></td>
                    <td class="text-end"><?= hx_link(with_back('/settings/posting-rules/' . $id . '/edit', $here), 'Change', 'btn btn-sm btn-light', 'id="posting-rule-row-' . $id . '-edit-btn"') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>

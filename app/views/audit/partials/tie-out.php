<?php /** The control tie-out (audit). Data: a, tz, here, currency */ ?>
<div class="card mb-3" id="audit-tie-out"><div class="card-header"><h5 class="card-title mb-0">The control tie-out as of <?= e(format_date($a['range']['to'])) ?></h5></div><div class="card-body">
    <div class="table-responsive"><table class="table table-sm mb-2" id="audit-tie-out-table"><thead><tr><th>Control</th><th class="text-end">Documents</th><th class="text-end">Ledger</th><th class="text-end">Difference</th></tr></thead><tbody>
        <?php foreach ($a['control'] as $c): $ok = m_cmp((string) $c['difference'], '0') === 0; ?>
            <tr id="audit-tie-<?= e($c['control']) ?>"><td><?= e(ucfirst($c['control'])) ?></td><td class="text-end"><?= e(fmt_money((string) $c['subledger'], $currency)) ?></td><td class="text-end"><?= e(fmt_money((string) $c['ledger'], $currency)) ?></td><td class="text-end <?= $ok ? 'text-success' : 'text-danger fw-semibold' ?>"><?= $ok ? 'ties' : e(fmt_money((string) $c['difference'], $currency)) ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <div class="fs-12 fw-semibold mb-1">Lines with no document behind them</div>
    <div class="fs-12 text-muted mb-1">Manual, opening, imported and recurring entries that touch the receivable or payable account — the reason a control can fail to tie.</div>
    <?php if ($a['control_lines'] === []): ?><div class="text-muted fs-12" id="audit-control-lines-empty">None.</div><?php endif; ?>
    <?php foreach ($a['control_lines'] as $l): $id = (int) $l['journal_entry_id']; ?>
        <div class="border rounded p-2 mb-2 fs-12" id="audit-control-line-<?= $id ?>-<?= e($l['account_code']) ?>"><?= hx_link(with_back('/journal/' . $id, $here), e((string) $l['entry_number']), 'fw-semibold') ?> · <?= e($l['account_code']) ?> <?= e($l['account_name']) ?> · <?= (float) $l['debit'] > 0 ? 'Dr ' . e(fmt_money((string) $l['debit'], $currency)) : 'Cr ' . e(fmt_money((string) $l['credit'], $currency)) ?> · <?= e($l['source']) ?> · dated <?= e(format_date((string) $l['entry_date'])) ?><?= $l['memo'] ? ' — ' . e((string) $l['memo']) : '' ?></div>
    <?php endforeach; ?>
</div></div>

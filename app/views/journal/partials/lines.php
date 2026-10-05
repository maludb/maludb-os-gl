<?php /** The lines region (#entry-lines): the editor for a draft, a read-only table otherwise; the forms of the editor sit after the table. Data: e, balance, pdo, may (write), departments, sites, taxRates */
$id = (int) $e['journal_entry_id']; $editable = $e['status'] === 'draft' && $may['write']; $here = '/journal/' . $id; ?>
<div id="entry-lines" class="card mb-3">
    <div class="card-header"><h5 class="card-title mb-0">Lines</h5><?php if ($e['status'] === 'posted' || $e['status'] === 'reversed'): ?><span class="fs-12 text-muted" id="entry-lines-readonly-note">Posted entries never change — reverse it to correct it.</span><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-sm mb-0" id="entry-lines-table">
        <thead><tr><th>#</th><th style="min-width:14rem">Account</th><th style="min-width:10rem">Description</th><th class="text-end" style="min-width:7rem">Debit</th><th class="text-end" style="min-width:7rem">Credit</th><th style="min-width:8rem">Department</th><th style="min-width:7rem">Site</th><th style="min-width:9rem">Customer / vendor</th><th style="min-width:7rem">Tax</th><?= $editable ? '<th></th>' : '' ?></tr></thead>
        <tbody>
            <?php if ($e['lines'] === [] && !$editable): ?><tr id="entry-lines-empty"><td colspan="9" class="text-center text-muted py-3">No lines.</td></tr><?php endif; ?>
            <?php foreach ($e['lines'] as $l): ?>
                <?php if ($editable): ?><?= view('journal/partials/line-row.php', ['e' => $e, 'l' => $l, 'pdo' => $pdo, 'departments' => $departments, 'sites' => $sites, 'taxRates' => $taxRates]) ?>
                <?php else: ?>
                    <tr id="entry-line-<?= (int) $l['journal_line_id'] ?>"><td class="text-muted fs-12"><?= (int) $l['line_no'] ?></td><td><?= hx_link(with_back('/accounts/' . (int) $l['account_id'], $here), e($l['account_code'] . ' — ' . $l['account_name'])) ?></td><td><?= e($l['description'] ?? '') ?></td>
                        <?= fmt_money_cell((float) $l['debit'] > 0 ? $l['debit'] : null, $e['currency']) ?><?= fmt_money_cell((float) $l['credit'] > 0 ? $l['credit'] : null, $e['currency']) ?>
                        <td class="fs-12"><?= e($l['department_name'] ?? '') ?></td><td class="fs-12"><?= e($l['site_name'] ?? '') ?></td><td class="fs-12"><?= e($l['customer_name'] ?? $l['vendor_name'] ?? '') ?></td><td class="fs-12"><?= e($l['tax_rate_name'] ?? '') ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($editable): ?><?= view('journal/partials/line-row.php', ['e' => $e, 'l' => null, 'pdo' => $pdo, 'departments' => $departments, 'sites' => $sites, 'taxRates' => $taxRates]) ?><?php endif; ?>
        </tbody>
        <tfoot><tr id="entry-lines-totals"><td colspan="3" class="text-end text-muted fs-12">Totals</td><?= fmt_money_cell($balance['debits'], $e['currency'], 'entry-lines-total-debits') ?><?= fmt_money_cell($balance['credits'], $e['currency'], 'entry-lines-total-credits') ?><td colspan="<?= $editable ? 5 : 4 ?>" class="fs-12 <?= $balance['balances'] ? 'text-success' : 'text-danger' ?>" id="entry-lines-difference"><?= $balance['balances'] ? 'Balances' : 'Difference ' . number_format((float) $balance['difference'], 2) ?></td></tr></tfoot>
    </table></div>
    <?php if ($editable): ?>
        <?php foreach ($e['lines'] as $l): $lid = (int) $l['journal_line_id']; ?>
            <form method="post" action="/journal/line-save.php" hx-post="/journal/line-save.php" hx-target="#entry-lines" hx-swap="outerHTML" id="line-form-<?= $lid ?>"><?= csrf_field() ?><input type="hidden" name="entry" value="<?= $id ?>"><input type="hidden" name="line" value="<?= $lid ?>"></form>
            <form method="post" action="/journal/line-remove.php" hx-post="/journal/line-remove.php" hx-target="#entry-lines" hx-swap="outerHTML" id="line-remove-<?= $lid ?>"><?= csrf_field() ?><input type="hidden" name="entry" value="<?= $id ?>"><input type="hidden" name="line" value="<?= $lid ?>"></form>
        <?php endforeach; ?>
        <form method="post" action="/journal/line-save.php" hx-post="/journal/line-save.php" hx-target="#entry-lines" hx-swap="outerHTML" id="line-form-new"><?= csrf_field() ?><input type="hidden" name="entry" value="<?= $id ?>"></form>
    <?php endif; ?>
</div>

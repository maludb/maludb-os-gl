<?php /** The account's activity with a running balance (Pattern B table; swapped by the filters). Data: a, range, activity (opening, rows), currency, here */ ?>
<div id="account-activity" class="table-responsive">
    <table class="table table-sm table-hover mb-0" id="account-activity-table">
        <thead><tr><th>Date</th><th>Entry</th><th>Source</th><th>Memo</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
        <tbody>
            <tr id="account-activity-opening"><td><?= e(format_date($range['from'])) ?></td><td></td><td class="text-muted">opening</td><td>Opening balance</td><td></td><td></td><?= fmt_money_cell($activity['opening'], $currency, 'account-activity-opening-balance') ?></tr>
            <?php if ($activity['rows'] === []): ?><tr id="account-activity-empty"><td colspan="7" class="text-center text-muted py-3">Nothing posted in this range.</td></tr><?php endif; ?>
            <?php foreach ($activity['rows'] as $r): $eid = (int) $r['entry_id']; ?>
                <tr id="account-activity-row-<?= $eid ?>"><td class="text-nowrap"><?= e(format_date($r['entry_date'])) ?></td>
                    <td class="text-nowrap"><?= hx_link(with_back('/journal/' . $eid, $here), e((string) $r['entry_number'])) ?></td>
                    <td class="text-muted"><?= e($r['source']) ?></td>
                    <td><?= e($r['memo'] ?? '') ?><?= $r['description'] ? '<div class="fs-12 text-muted">' . e($r['description']) . '</div>' : '' ?></td>
                    <?= fmt_money_cell((float) $r['debit'] == 0 ? null : $r['debit'], $currency) ?><?= fmt_money_cell((float) $r['credit'] == 0 ? null : $r['credit'], $currency) ?><?= fmt_money_cell($r['running_balance'], $currency) ?></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

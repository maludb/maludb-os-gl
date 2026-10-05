<?php /** One entry as a table row (the desktop). Data: e, here */ $id = (int) $e['journal_entry_id']; $url = with_back('/journal/' . $id, $here); ?>
<tr id="entry-row-<?= $id ?>">
    <td class="text-nowrap"><?= hx_link($url, $e['number'] !== null ? e($e['number']) : '<span class="badge bg-soft-secondary text-secondary">Draft #' . $id . '</span>', 'fw-semibold') ?><?= (int) $e['attachment_count'] > 0 ? ' <i class="feather-paperclip fs-12 text-muted"></i>' : '' ?></td>
    <td class="text-nowrap"><?= e(format_date($e['entry_date'])) ?></td>
    <td><?php if ($e['period_name'] !== null): ?><span class="badge bg-<?= $e['period_status'] === 'closed' ? 'dark' : ((int) $e['period_reopen_count'] > 0 ? 'warning text-dark' : 'success') ?>"><?= e($e['period_name']) ?></span><?php else: ?><span class="badge bg-soft-danger text-danger">no period</span><?php endif; ?></td>
    <td class="text-muted"><?= e($e['source_label']) ?></td>
    <td><?= e($e['memo'] ?? '') ?></td>
    <?= fmt_money_cell($e['total'], $e['currency']) ?>
    <td><span class="badge bg-<?= ENTRY_STATUS_BADGE[$e['status']] ?>"><?= e($e['status']) ?></span><?php if ($e['status'] === 'draft'): ?> <span class="badge bg-soft-<?= $e['balances'] ? 'success text-success' : 'danger text-danger' ?>" id="entry-row-<?= $id ?>-balance"><?= $e['balances'] ? 'balances' : ((float) $e['draft_debit'] == 0 && (float) $e['draft_credit'] == 0 ? 'no lines' : 'out by ' . number_format(abs((float) $e['difference']), 2)) ?></span><?php endif; ?></td>
    <td class="fs-12 text-muted text-nowrap"><?= e($e['created_by_name'] ?? '') ?><?= $e['created_by_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?></td>
</tr>

<?php /** One bank line as a table row. Data: l, matched (item or null), here, may */ $id = (int) $l['bank_transaction_id']; ?>
<tr id="bank-line-row-<?= $id ?>">
    <td class="text-nowrap"><?= e(format_date($l['txn_date'])) ?></td>
    <td><?= e($l['description']) ?><?= $l['external_ref'] && !str_starts_with((string) $l['external_ref'], 'h:') ? ' <span class="fs-12 text-muted">' . e($l['external_ref']) . '</span>' : '' ?><?php if ($l['status'] === 'ignored'): ?><div class="fs-12 text-muted">Ignored: <?= e((string) $l['ignore_reason']) ?></div><?php endif; ?></td>
    <td class="text-end text-nowrap <?= m_cmp((string) $l['amount'], '0') < 0 ? 'text-danger' : 'text-success' ?>"><?= e(fmt_money($l['amount'], 'USD', false)) ?></td>
    <td><span class="badge bg-<?= LINE_STATUS_BADGE[$l['status']] ?>"><?= e(LINE_STATUSES[$l['status']]) ?></span><?= $l['reconciliation_id'] !== null ? ' <span class="badge bg-soft-success text-success" title="Reconciled">✓</span>' : '' ?>
        <?php if ($matched !== null): ?><div class="fs-12"><?= hx_link(with_back($matched['url'], $here), e($matched['label']), '') ?></div><?php endif; ?></td>
    <td class="text-end"><?php if ($l['status'] === 'unmatched' && $may['write']): ?><?= hx_link(with_back('/cash/match?bank_account=' . (int) $l['bank_account_id'], $here), 'Match', 'btn btn-sm btn-light') ?>
        <?php elseif ($l['status'] !== 'unmatched' && $may['write'] && $l['reconciliation_id'] === null): ?><form method="post" action="/cash/unmatch.php" hx-post="/cash/unmatch.php" hx-target="#flash" hx-confirm="Free this bank line?" class="d-inline"><?= csrf_field() ?><input type="hidden" name="bank_line" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light" id="bank-line-row-<?= $id ?>-unmatch-btn">Unmatch</button></form><?php endif; ?></td>
</tr>

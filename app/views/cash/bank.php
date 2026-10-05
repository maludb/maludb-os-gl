<?php /** One bank account (screen `bank-account-view`). Data: bank, lines, matched, counts, total, page, filters, recons, ledger, transfers, timeline, may, here, tz, notice */
$id = (int) $bank['bank_account_id']; $cur = $bank['currency']; $qs = http_build_query(array_filter($filters, static fn ($v) => $v !== '')); $pages = (int) ceil($total / BANK_LINE_PAGE);
$tabs = [['all', 'All', '', $counts['all']], ['unmatched', 'Unmatched', 'unmatched', $counts['unmatched']], ['matched', 'Matched', 'matched', $counts['matched']], ['ignored', 'Ignored', 'ignored', $counts['ignored']]]; ?>
<?= view('shared/header.php', ['id' => 'bank-account-view', 'title' => $bank['name'], 'crumbs' => [['Home', '/'], ['Bank accounts', '/cash/'], [$bank['name'], null]], 'back' => back_link() ?? ['/cash/', 'bank accounts'],
    'action' => $may['settings'] ? hx_link(with_back('/settings/banks/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="bank-view-edit-btn"') : '']) ?>
<div class="main-content" id="bank-account-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('cash/partials/bank-figures.php', ['bank' => $bank]) ?>
    <div class="d-flex flex-wrap gap-2 mb-3" id="bank-view-actions">
        <?php if ($may['write']): ?><?= hx_link(with_back('/cash/import?bank_account=' . $id, $here), 'Import', 'btn btn-light btn-touch', 'id="bank-view-import-btn"') ?><?= hx_link(with_back('/cash/match?bank_account=' . $id, $here), 'Match', 'btn btn-light btn-touch', 'id="bank-view-match-btn"') ?><?= hx_link(with_back('/cash/reconcile?bank_account=' . $id, $here), 'Reconcile', 'btn btn-light btn-touch', 'id="bank-view-reconcile-btn"') ?><?= hx_link(with_back('/cash/transfers/new?from=' . $id, $here), 'Transfer out', 'btn btn-light btn-touch', 'id="bank-view-transfer-btn"') ?><?php endif; ?>
    </div>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3" id="bank-lines-card"><div class="card-header"><h5 class="card-title mb-0">Lines</h5></div>
                <div class="card-body pb-0"><div class="d-flex flex-wrap gap-2 mb-2" id="bank-lines-tabs"><?php foreach ($tabs as [$tk, $tl, $ts, $tn]): ?><?= hx_link('/cash/banks/' . $id . ($ts === '' ? '' : '?status=' . $ts), e($tl) . ' <span class="badge bg-soft-secondary text-secondary">' . (int) $tn . '</span>', 'btn btn-sm ' . ($filters['status'] === $ts ? 'btn-primary' : 'btn-light'), 'id="bank-lines-tab-' . $tk . '"') ?><?php endforeach; ?></div></div>
                <?php if ($lines === []): ?><div class="card-body text-muted" id="bank-lines-empty">No lines here.</div><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0" id="bank-lines-table"><thead><tr><th>Date</th><th>Description</th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead><tbody>
                    <?php foreach ($lines as $l): ?><?= view('cash/partials/line-row.php', ['l' => $l, 'matched' => $matched[$l['bank_transaction_id']], 'here' => $here, 'may' => $may]) ?><?php endforeach; ?>
                </tbody></table></div>
                <div class="card-body fs-12 d-flex justify-content-between" id="bank-lines-pager"><span class="text-muted"><?= (int) $total ?> line<?= $total === 1 ? '' : 's' ?><?= $pages > 1 ? ', page ' . $page . ' of ' . $pages : '' ?></span>
                    <span><?php if ($page > 1): ?><?= hx_link('/cash/banks/' . $id . '?' . $qs . '&page=' . ($page - 1), 'Newer', 'btn btn-sm btn-light') ?> <?php endif; ?><?php if ($page < $pages): ?><?= hx_link('/cash/banks/' . $id . '?' . $qs . '&page=' . ($page + 1), 'Older', 'btn btn-sm btn-light') ?><?php endif; ?></span></div><?php endif; ?>
            </div>
            <div class="card mb-3" id="bank-ledger"><div class="card-header"><h5 class="card-title mb-0">Ledger activity</h5><?= hx_link(with_back('/accounts/' . (int) $bank['account_id'], $here), 'The account ' . e($bank['account_code']), 'fs-12') ?></div>
                <?php if ($ledger === []): ?><div class="card-body text-muted" id="bank-ledger-empty">No posting on the account in the last 90 days.</div><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0 fs-12"><thead><tr><th>Date</th><th>Entry</th><th>Memo</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead><tbody>
                    <?php foreach (array_slice($ledger, 0, 25) as $a): ?><tr><td class="text-nowrap"><?= e(format_date($a['entry_date'])) ?></td><td><?= hx_link(with_back('/journal/' . (int) $a['entry_id'], $here), e((string) $a['entry_number'])) ?></td><td><?= e((string) ($a['memo'] ?? $a['description'] ?? '')) ?></td><?= fmt_money_cell($a['debit'], $cur) ?><?= fmt_money_cell($a['credit'], $cur) ?></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card mb-3" id="bank-recons"><div class="card-header"><h5 class="card-title mb-0">Reconciliations</h5></div>
                <?php if ($recons === []): ?><div class="card-body text-muted" id="bank-recons-empty">Never reconciled.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($recons as $r): ?><div class="list-group-item d-flex justify-content-between align-items-center" id="bank-recon-<?= $r['bank_reconciliation_id'] ?>"><span><?= hx_link(with_back('/cash/reconciliations/' . $r['bank_reconciliation_id'], $here), e(format_date($r['statement_date']))) ?> <span class="badge bg-<?= RECON_STATUS_BADGE[$r['status']] ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></span><span class="fs-12"><?= e(fmt_money($r['statement_balance'], $cur, false)) ?></span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <div class="card mb-3" id="bank-transfers"><div class="card-header"><h5 class="card-title mb-0">Transfers</h5><?= hx_link(with_back('/cash/transfers/?bank_account=' . $id, $here), 'All', 'fs-12') ?></div>
                <?php if ($transfers === []): ?><div class="card-body text-muted">None.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($transfers as $t): $out = $t['from_bank_account_id'] === $id; ?><div class="list-group-item d-flex justify-content-between"><span><?= hx_link(with_back('/cash/transfers/' . $t['transfer_id'], $here), e($t['number'])) ?> <span class="fs-12 text-muted"><?= $out ? '→ ' . e($t['to_bank_name']) : '← ' . e($t['from_bank_name']) ?></span></span><span class="<?= $out ? 'text-danger' : 'text-success' ?>"><?= e(fmt_money($out ? m_neg((string) $t['amount']) : $t['amount'], $cur, false)) ?></span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'bank']) ?>
        </div>
    </div>
</div>

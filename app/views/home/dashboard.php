<?php
/** The dashboard (screen `dashboard`). Data: h (home_summary), tz, may. Every region is an empty state until its slice fills it (docs/build-specs/sso-shell.md).
 *  Regions: the fiscal-year banner, Cash (6), Who owes us / Whom we owe (4, 5), What waits (2, 4, 5, 6), This month (3), The AI bill (7), Posted through, The morning note (8). */
$w = $h['waiting'];
$cur = $h['base_currency'];
$count = static fn (string $id, int|null $n, string $label, string $url, string $icon): string => '<div class="col-6 col-md-4 col-xl-2">' . hx_link($url, '<div class="card stretch stretch-full mb-0" id="' . e($id) . '"><div class="card-body p-3 text-center">'
    . '<div class="fs-4 fw-bold ' . ((int) $n > 0 ? 'text-dark' : 'text-muted') . '" id="' . e($id) . '-count">' . (int) $n . '</div><div class="fs-11 text-muted text-uppercase"><i class="' . e($icon) . ' me-1"></i>' . e($label) . '</div></div></div>', 'text-decoration-none', 'id="' . e($id) . '-link"') . '</div>';
?>
<?= view('shared/header.php', ['id' => 'dashboard', 'title' => 'Home', 'crumbs' => [[$h['business_name'] ?: app_name(), null]],
    'action' => $may['journal'] ? hx_link('/journal/new', '<i class="feather-plus-circle me-1"></i>New entry', 'btn btn-primary btn-touch', 'id="dashboard-new-entry-btn"') : '']) ?>
<div class="main-content" id="dashboard-content">
    <?php if (!$h['has_fiscal_year']): ?>
        <div class="alert alert-warning d-flex align-items-center gap-2" id="home-no-fiscal-year" role="status">
            <i class="feather-calendar"></i>
            <div><span class="fw-semibold">Open your first fiscal year to start posting.</span>
                <?= $may['admin'] ? hx_link('/periods/new', 'Open a fiscal year', 'alert-link ms-1', 'id="home-open-year-link"') : '<span class="fs-12 text-muted ms-1">A GL admin opens it under Periods.</span>' ?></div>
        </div>
    <?php endif; ?>
    <div class="fs-12 text-muted mb-3" id="home-posted-through">
        <?php if ($h['posted_through'] !== null): ?>Posted through <span class="fw-semibold text-dark" id="home-posted-through-date"><?= e(date('j M Y', strtotime($h['posted_through']))) ?></span><?php else: ?>Nothing posted yet.<?php endif; ?>
        <?php if ($h['fiscal_year'] !== null): ?> · <?= e($h['fiscal_year']['name']) ?> open<?php endif; ?>
    </div>

    <h6 class="fs-12 text-uppercase text-muted mb-2">What waits</h6>
    <div class="row g-2 mb-4" id="home-waiting">
        <?= $count('home-wait-drafts', $w['draft_entries'] ?? 0, 'Draft entries', '/journal/?status=draft', 'feather-edit-3') ?>
        <?= $count('home-wait-invoices', $w['draft_invoices'] ?? 0, 'Draft invoices', '/invoices/?status=draft', 'feather-file-plus') ?>
        <?= $count('home-wait-bills', $w['bills_awaiting_approval'] ?? 0, 'Bills to approve', '/bills/?status=draft', 'feather-file-minus') ?>
        <?= $count('home-wait-payments', $w['payments_awaiting_approval'] ?? 0, 'Payments to approve', '/payments/?status=draft', 'feather-upload') ?>
        <?= $count('home-wait-bank', $w['unmatched_bank_lines'] ?? 0, 'Bank lines to match', '/cash/match', 'feather-check-square') ?>
        <?= $count('home-wait-claims', $w['open_claims'] ?? 0, 'Customer claims', '/receipts/claims', 'feather-message-square') ?>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-cash">
                <div class="card-header"><h5 class="card-title">Cash</h5><?= hx_link('/cash/', 'Bank accounts', 'fs-12') ?></div>
                <div class="card-body">
                    <?php if ($h['cash'] === []): ?>
                        <div class="empty-state" id="home-cash-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-credit-card"></i></span>
                            <div><div class="fw-semibold">No bank account yet</div><div class="fs-12 text-muted">Add the business's bank accounts under Settings; their ledger and cleared balances appear here.</div></div></div>
                    <?php else: ?>
                        <div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="home-cash-table"><thead class="thead-light"><tr><th>Account</th><th class="text-end">Ledger</th><th class="text-end">Cleared</th><th class="d-none d-md-table-cell">Reconciled through</th></tr></thead><tbody>
                        <?php foreach ($h['cash'] as $c): ?><tr id="home-cash-<?= (int) $c['bank_account_id'] ?>"><td><?= hx_link('/cash/banks/' . (int) $c['bank_account_id'], e($c['name']), 'text-dark fw-semibold') ?><?= (int) $c['unmatched_lines'] > 0 ? ' <span class="badge bg-soft-warning text-warning">' . (int) $c['unmatched_lines'] . ' to match</span>' : '' ?></td><?= fmt_money_cell($c['ledger_balance'], $c['currency']) ?><?= fmt_money_cell($c['cleared_balance'], $c['currency']) ?><td class="d-none d-md-table-cell text-muted"><?= $c['last_reconciled_through'] ? e(date('j M Y', strtotime($c['last_reconciled_through']))) : 'never' ?></td></tr><?php endforeach; ?>
                        </tbody><?php if (count($h['cash_totals'] ?? []) > 0): ?><tfoot id="home-cash-totals"><?php foreach ($h['cash_totals'] as $t): ?><tr><td class="text-muted">Total in <?= e($t['currency']) ?></td><?= fmt_money_cell($t['ledger_balance'], $t['currency'], 'home-cash-total-' . $t['currency']) ?><td></td><td class="d-none d-md-table-cell"></td></tr><?php endforeach; ?></tfoot><?php endif; ?></table></div>
                        <?php if (!empty($h['cash_trend'])): $tr = $h['cash_trend']; ?>
                        <details class="mt-3" id="home-cash-trend"><summary class="fs-12 fw-semibold" style="min-height:44px;display:flex;align-items:center">Twelve months of ledger balances</summary>
                            <div class="table-responsive"><table class="table table-sm mb-0 fs-12" id="home-cash-trend-table"><thead class="thead-light"><tr><th>Month end</th><?php foreach ($tr['accounts'] as $a): ?><th class="text-end"><?= e($a['name']) ?></th><?php endforeach; ?></tr></thead><tbody>
                            <?php foreach (array_reverse($tr['months']) as $m): ?><tr id="home-cash-trend-<?= e($m) ?>"><td class="text-nowrap"><?= e(date('M Y', strtotime($m))) ?></td><?php foreach ($tr['accounts'] as $a): ?><?= fmt_money_cell($tr['rows'][$m][$a['bank_account_id']], $a['currency']) ?><?php endforeach; ?></tr><?php endforeach; ?>
                            </tbody></table></div></details>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-this-month">
                <div class="card-header"><h5 class="card-title">This month</h5><?= hx_link('/statements/', 'Statements', 'fs-12') ?></div>
                <div class="card-body">
                    <?php if ($h['this_month'] === null || $h['this_month']['net_income'] === null): ?>
                        <div class="empty-state" id="home-this-month-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-file-text"></i></span>
                            <div><div class="fw-semibold">No books yet</div><div class="fs-12 text-muted">Net income for the month appears here once entries post.</div></div></div>
                    <?php else: ?>
                        <div class="d-flex align-items-baseline gap-2"><span class="fs-3 fw-bold <?= (float) $h['this_month']['net_income'] < 0 ? 'text-danger' : 'text-dark' ?>" id="home-net-income"><?= e(fmt_money($h['this_month']['net_income'], $cur)) ?></span><span class="fs-12 text-muted">net income, <?= e(date('M Y', strtotime($h['this_month']['from']))) ?> to date</span></div>
                        <div class="fs-12 mt-2"><?= hx_link('/statements/pl?from=' . e($h['this_month']['from']) . '&to=' . e($h['this_month']['to']), 'Profit and loss', 'me-3') ?><?= hx_link('/statements/balance-sheet', 'Balance sheet', 'me-3') ?><?= hx_link('/statements/cash-flow', 'Cash flow') ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-receivables">
                <div class="card-header"><h5 class="card-title">Who owes us</h5><?= hx_link('/reports/ar-aging', 'Aging', 'fs-12') ?></div>
                <div class="card-body">
                    <?php if ($h['ar'] === []): ?>
                        <div class="empty-state" id="home-receivables-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-users"></i></span>
                            <div><div class="fw-semibold">Nobody owes us anything</div><div class="fs-12 text-muted">Open invoices and how overdue they are appear here.</div></div></div>
                    <?php else: ?>
                        <?php foreach ($h['ar'] as $r): ?><div class="d-flex justify-content-between align-items-baseline mb-1" id="home-ar-<?= e($r['currency']) ?>"><span class="fs-4 fw-bold text-dark"><?= e(fmt_money($r['total'], $r['currency'])) ?></span><span class="fs-12 text-muted"><?= (int) $r['open_count'] ?> open · <?= e(fmt_money($r['over_90'], $r['currency'])) ?> over 90 days</span></div><?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-payables">
                <div class="card-header"><h5 class="card-title">Whom we owe</h5><?= hx_link('/reports/ap-aging', 'Aging', 'fs-12') ?></div>
                <div class="card-body">
                    <?php if ($h['ap'] === []): ?>
                        <div class="empty-state" id="home-payables-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-truck"></i></span>
                            <div><div class="fw-semibold">We owe nobody</div><div class="fs-12 text-muted">Approved bills and when they are due appear here.</div></div></div>
                    <?php else: ?>
                        <?php foreach ($h['ap'] as $r): ?><div class="d-flex justify-content-between align-items-baseline mb-1" id="home-ap-<?= e($r['currency']) ?>"><span class="fs-4 fw-bold text-dark"><?= e(fmt_money($r['total'], $r['currency'])) ?></span><span class="fs-12 text-muted"><?= (int) $r['open_count'] ?> open · <?= e(fmt_money($r['over_90'], $r['currency'])) ?> over 90 days</span></div><?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-ai">
                <div class="card-header"><h5 class="card-title">The AI bill</h5><?= hx_link('/ap/ai/', 'Statements', 'fs-12') ?></div>
                <div class="card-body">
                    <?php if ($h['ai'] === null): ?>
                        <div class="empty-state" id="home-ai-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-cpu"></i></span>
                            <div><div class="fw-semibold">No AI statement pulled yet</div><div class="fs-12 text-muted">The OS's monthly token statement becomes one bill per provider here.</div></div></div>
                    <?php else: $totals = json_decode((string) $h['ai']['totals'], true) ?: []; ?>
                        <div class="d-flex align-items-center gap-2" id="home-ai-latest"><span class="fw-semibold"><?= e($h['ai']['period']) ?></span>
                            <span class="badge bg-soft-<?= $h['ai']['kernel_status'] === 'closed' ? 'dark text-dark' : 'info text-info' ?>"><?= e($h['ai']['kernel_status']) ?> in the OS</span>
                            <span class="badge bg-soft-<?= $h['ai']['status'] === 'agrees' ? 'success text-success' : ($h['ai']['status'] === 'differs' ? 'danger text-danger' : 'secondary text-secondary') ?>"><?= e($h['ai']['status']) ?></span></div>
                        <div class="fs-12 text-muted mt-1"><?php foreach ($totals as $c => $amt): ?><span class="me-2"><?= e(fmt_money($amt, (string) $c)) ?></span><?php endforeach; ?><?= (int) $h['ai']['bill_count'] ?> bill<?= (int) $h['ai']['bill_count'] === 1 ? '' : 's' ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card stretch stretch-full" id="home-morning-note">
                <div class="card-header"><h5 class="card-title">The morning note</h5><?= hx_link('/settings/agents', 'Agents', 'fs-12') ?></div>
                <div class="card-body">
                    <?php if ($h['morning_note'] === null): ?>
                        <div class="empty-state" id="home-morning-note-empty"><span class="avatar-text avatar-lg rounded"><i class="feather-sunrise"></i></span>
                            <div><div class="fw-semibold">No note yet</div><div class="fs-12 text-muted">The Bookkeeper agent leaves a note each morning: what it imported, matched and drafted, and what needs a person.</div></div></div>
                    <?php else: ?>
                        <div class="fs-12 text-muted mb-1"><?= e(format_ts($h['morning_note']['created_at'], $tz, 'M j, g:i A')) ?></div>
                        <div class="fw-semibold"><?= e($h['morning_note']['title']) ?></div>
                        <div class="fs-12 text-wrap" id="home-morning-note-body" style="white-space: pre-line"><?= e((string) $h['morning_note']['body']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

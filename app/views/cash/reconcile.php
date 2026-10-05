<?php /** Reconcile (screen `bank-reconcile`). Data: bank, banks, rec, lines, counts, may, here, notice */ $id = $bank['bank_account_id'] ?? null; $cur = $bank['currency'] ?? 'USD'; $inProgress = $rec !== null && $rec['status'] === 'in_progress'; $zero = $inProgress && m_cmp((string) $rec['difference'], '0') === 0; ?>
<?= view('shared/header.php', ['id' => 'bank-reconcile', 'title' => 'Reconcile', 'crumbs' => [['Home', '/'], ['Bank accounts', '/cash/'], ['Reconcile', null]], 'back' => back_link() ?? ['/cash/', 'bank accounts']]) ?>
<div class="main-content" id="bank-reconcile-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
<?php if ($bank === null): ?>
    <div class="card"><div class="card-body text-center text-muted py-4" id="bank-reconcile-nobank">No bank account yet. <?= hx_link('/settings/banks/new', 'Add one') ?>.</div></div>
<?php else: ?>
    <form method="get" action="/cash/reconcile" hx-get="/cash/reconcile" hx-target="#page-content" hx-push-url="true" id="bank-reconcile-pick" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-9"><label class="form-label fs-12 text-muted" for="bank-reconcile-bank">Bank account</label><select name="bank_account" id="bank-reconcile-bank" class="form-select btn-touch"><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>" <?= $b['bank_account_id'] === $id ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['currency']) ?>)</option><?php endforeach; ?></select></div>
        <div class="col-12 col-md-3"><button type="submit" class="btn btn-light btn-touch w-100" id="bank-reconcile-pick-btn">Show</button></div>
    </div></form>
    <?php if ($rec === null): ?>
        <div class="card mb-3" id="bank-reconcile-start"><div class="card-header"><h5 class="card-title mb-0">Start a reconciliation of <?= e($bank['name']) ?></h5></div><div class="card-body">
            <div class="fs-12 text-muted mb-3" id="bank-reconcile-last"><?= $bank['last_reconciled_through'] ? 'Reconciled through ' . e(format_date($bank['last_reconciled_through'])) . ' at ' . e(fmt_money($bank['last_reconciled_balance'], $cur)) . '. The next statement is dated after that.' : 'Never reconciled: the first statement starts from the opening balance of ' . e(fmt_money($bank['opening_balance'], $cur)) . '.' ?></div>
            <form method="post" action="/cash/reconcile-start.php" hx-post="/cash/reconcile-start.php" hx-target="#flash" id="bank-reconcile-start-form" class="row g-2"><?= csrf_field() ?><input type="hidden" name="bank_account" value="<?= (int) $id ?>">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bank-reconcile-date">Statement date</label><input type="date" name="statement_date" id="bank-reconcile-date" class="form-control btn-touch" required value="<?= e(date('Y-m-d')) ?>"></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="bank-reconcile-balance">Statement balance</label><input type="text" inputmode="decimal" name="statement_balance" id="bank-reconcile-balance" class="form-control btn-touch" required placeholder="11,336.44"></div>
                <div class="col-12"><button type="submit" class="btn btn-primary btn-touch w-100" id="bank-reconcile-start-btn">Start</button></div></form>
        </div></div>
    <?php else: ?>
        <div class="card mb-3" id="reconcile-figures"><div class="card-body p-3">
            <div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-semibold">To <?= e(format_date($rec['statement_date'])) ?> <span class="badge bg-<?= RECON_STATUS_BADGE[$rec['status']] ?>"><?= e(str_replace('_', ' ', $rec['status'])) ?></span></div><?= $rec['status'] !== 'in_progress' ? hx_link(with_back('/cash/reconciliations/' . $rec['bank_reconciliation_id'], $here), 'Open', 'fs-12') : '' ?></div>
            <div class="row g-2 fs-12 text-center">
                <div class="col-6 col-md-3"><div class="text-muted">Statement balance</div><div class="fw-bold fs-14" id="reconcile-statement"><?= e(fmt_money($rec['statement_balance'], $cur)) ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Opening (last statement)</div><div class="fw-bold fs-14" id="reconcile-opening"><?= e(fmt_money($rec['opening_balance'], $cur)) ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Cleared (matched)</div><div class="fw-bold fs-14" id="reconcile-cleared"><?= e(fmt_money($rec['cleared_balance'], $cur)) ?></div></div>
                <div class="col-6 col-md-3"><div class="text-muted">Difference</div><div class="fw-bold fs-14 <?= m_cmp((string) $rec['difference'], '0') === 0 ? 'text-success' : 'text-danger' ?>" id="reconcile-difference"><?= e(fmt_money($rec['difference'], $cur)) ?></div></div>
            </div>
            <div class="fs-12 text-muted text-center mt-2" id="reconcile-ledger">The ledger says <?= e(fmt_money((string) $rec['ledger_balance'], $cur)) ?> at that date.</div>
        </div></div>
        <?php if ($inProgress): ?>
        <div class="card mb-3" id="reconcile-actions"><div class="card-body">
            <?php if ($may['reconcile']): ?><form method="post" action="/cash/reconcile-complete.php" hx-post="/cash/reconcile-complete.php" hx-target="#flash" hx-confirm="Complete this reconciliation? The lines through the date are stamped." class="mb-2"><?= csrf_field() ?><input type="hidden" name="reconciliation" value="<?= (int) $rec['bank_reconciliation_id'] ?>">
                <input type="text" name="note" class="form-control btn-touch mb-2" maxlength="500" placeholder="A note (optional)" id="reconcile-note"><button type="submit" class="btn btn-primary btn-touch w-100" id="reconcile-complete-btn" <?= $zero ? '' : 'disabled' ?>>Complete</button>
                <?php if (!$zero): ?><div class="fs-12 text-muted mt-1" id="reconcile-complete-why">Complete is open only at a zero difference: match or ignore every line through <?= e(format_date($rec['statement_date'])) ?>.</div><?php endif; ?></form>
            <?php else: ?><div class="fs-12 text-muted mb-2" id="reconcile-complete-note">The controller completes the reconciliation once the difference is zero.</div><?php endif; ?>
            <form method="post" action="/cash/reconcile-abandon.php" hx-post="/cash/reconcile-abandon.php" hx-target="#flash" hx-confirm="Abandon this reconciliation? Nothing was stamped."><?= csrf_field() ?><input type="hidden" name="reconciliation" value="<?= (int) $rec['bank_reconciliation_id'] ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="reconcile-abandon-btn">Abandon</button></form>
        </div></div>
        <?php endif; ?>
        <?= view('cash/partials/recon-lines.php', ['rec' => $rec, 'lines' => $lines, 'counts' => $counts, 'bank' => $bank, 'here' => $here, 'may' => $may]) ?>
    <?php endif; ?>
<?php endif; ?>
</div>

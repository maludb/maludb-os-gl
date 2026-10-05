<?php /** One reconciliation (screen `reconciliation-view`). Data: rec, lines, latest, currency, may, timeline, here, tz, notice */ $cur = (string) ($currency ?? 'USD'); $counts = ['matched' => 0, 'ignored' => 0, 'unmatched' => 0]; foreach ($lines as $l) { $counts[$l['status']]++; } $id = (int) $rec['bank_reconciliation_id']; ?>
<?= view('shared/header.php', ['id' => 'reconciliation-view', 'title' => 'Reconciliation to ' . format_date($rec['statement_date']), 'crumbs' => [['Home', '/'], ['Bank accounts', '/cash/'], [$rec['bank_account_name'], '/cash/banks/' . (int) $rec['bank_account_id']], [format_date($rec['statement_date']), null]], 'back' => back_link() ?? ['/cash/banks/' . (int) $rec['bank_account_id'], 'the bank account']]) ?>
<div class="main-content" id="reconciliation-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="reconciliation-head"><div class="card-body p-3">
        <div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-semibold"><?= hx_link(with_back('/cash/banks/' . (int) $rec['bank_account_id'], $here), e($rec['bank_account_name']), 'text-dark') ?></div><span class="badge bg-<?= RECON_STATUS_BADGE[$rec['status']] ?>" id="reconciliation-head-status"><?= e(str_replace('_', ' ', $rec['status'])) ?></span></div>
        <div class="row g-2 fs-12 text-center">
            <div class="col-6 col-md-3"><div class="text-muted">Statement balance</div><div class="fw-bold fs-14" id="reconciliation-statement"><?= e(fmt_money($rec['statement_balance'], $cur)) ?></div></div>
            <div class="col-6 col-md-3"><div class="text-muted">Opening</div><div class="fw-bold fs-14" id="reconciliation-opening"><?= e(fmt_money($rec['opening_balance'], $cur)) ?></div></div>
            <div class="col-6 col-md-3"><div class="text-muted">Cleared</div><div class="fw-bold fs-14" id="reconciliation-cleared"><?= e(fmt_money($rec['cleared_balance'], $cur)) ?></div></div>
            <div class="col-6 col-md-3"><div class="text-muted">Difference</div><div class="fw-bold fs-14" id="reconciliation-difference"><?= e(fmt_money($rec['difference'], $cur)) ?></div></div>
        </div>
        <div class="fs-12 text-muted mt-2" id="reconciliation-facts"><?= $rec['completed_at'] ? 'Completed by ' . e((string) $rec['completed_by_name']) . ', ' . e(format_ts($rec['completed_at'], $tz)) : 'Not completed' ?><?= $rec['status'] === 'reopened' ? ' · reopened ' . e(format_ts($rec['reopened_at'], $tz)) . ': ' . e((string) $rec['reopen_reason']) : '' ?><?= $rec['note'] ? ' · ' . e($rec['note']) : '' ?> · <?= (int) $rec['line_count'] ?> line<?= (int) $rec['line_count'] === 1 ? '' : 's' ?></div>
    </div></div>
    <?php if ($rec['status'] === 'completed' && $may['reconcile']): ?>
        <div class="card mb-3" id="reconciliation-actions"><div class="card-body">
            <?php if ($latest): ?><form method="post" action="/cash/reconcile-reopen.php" hx-post="/cash/reconcile-reopen.php" hx-target="#flash" hx-confirm="Reopen this reconciliation? Its lines are freed."><?= csrf_field() ?><input type="hidden" name="reconciliation" value="<?= $id ?>">
                <input type="text" name="reason" class="form-control btn-touch mb-2" maxlength="500" required placeholder="Why it is reopened" id="reconciliation-reopen-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="reconciliation-reopen-btn">Reopen</button></form>
            <?php else: ?><div class="fs-12 text-muted" id="reconciliation-reopen-latest">Only the latest completed reconciliation is reopened — reopen the later ones first.</div><?php endif; ?>
        </div></div>
    <?php endif; ?>
    <?= view('cash/partials/recon-lines.php', ['rec' => $rec, 'lines' => $lines, 'counts' => $counts, 'bank' => ['currency' => $cur], 'here' => $here, 'may' => ['write' => false]]) ?>
    <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'reconciliation']) ?>
</div>

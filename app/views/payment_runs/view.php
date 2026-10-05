<?php /** One payment run (screen `payment-run-view`). Data: run, more, attachments, notes, timeline, may, here, tz, notice */
$id = (int) $run['payment_run_id']; $cur = $run['currency']; $st = $run['status']; ?>
<?= view('shared/header.php', ['id' => 'payment-run-view', 'title' => $run['number'], 'crumbs' => [['Home', '/'], ['Payment runs', '/payment-runs/'], [$run['number'], null]], 'back' => back_link() ?? ['/payment-runs/', 'payment runs'],
    'action' => $may['export'] && $run['payments'] !== [] ? '<a class="btn btn-light btn-touch" href="/payment-runs/' . $id . '?format=csv" id="payment-run-csv-link"><i class="feather-download me-1"></i>CSV</a>' : '']) ?>
<div class="main-content" id="payment-run-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="payment-run-head"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fs-20 fw-bold" id="payment-run-head-total"><?= e(fmt_money($run['total'], $cur)) ?></div><span class="d-flex gap-1"><span class="badge bg-<?= RUN_STATUS_BADGE[$st] ?>" id="payment-run-head-status"><?= e(PAYMENT_STATUSES[$st]) ?></span><?php if ($run['partly_recorded']): ?><span class="badge bg-soft-warning text-warning" id="payment-run-head-partly">partly recorded</span><?php endif; ?></span></div>
                <dl class="row mb-0 fs-12 mt-2">
                    <dt class="col-5 text-muted">Pay from</dt><dd class="col-7" id="payment-run-head-bank"><?= e($run['bank_account_name']) ?></dd>
                    <dt class="col-5 text-muted">Pay date</dt><dd class="col-7"><?= e(format_date($run['pay_date'])) ?></dd>
                    <dt class="col-5 text-muted">Payments</dt><dd class="col-7"><?= count($run['payments']) - $run['void_count'] ?><?= $run['void_count'] > 0 ? ' (' . $run['void_count'] . ' void)' : '' ?></dd>
                    <?php if ($run['memo']): ?><dt class="col-5 text-muted">Memo</dt><dd class="col-7"><?= e($run['memo']) ?></dd><?php endif; ?>
                    <?php if ($run['approved_at']): ?><dt class="col-5 text-muted">Approved</dt><dd class="col-7"><?= e((string) $run['approved_by_name']) ?>, <?= e(format_ts($run['approved_at'], $tz)) ?></dd><?php endif; ?>
                </dl>
            </div></div>
            <?php if ($st !== 'void' && ($may['write'] || $may['approve'] || $may['override'])): ?>
            <div class="card mb-3" id="payment-run-actions"><div class="card-body">
                <?php if ($st === 'draft' && $may['approve']): $needsCheques = count(array_filter($run['payments'], static fn (array $p): bool => $p['method'] === 'cheque' && !$p['cheque_number'] && $p['status'] === 'draft')) > 0; ?>
                    <form method="post" action="/payment-runs/approve.php" hx-post="/payment-runs/approve.php" hx-target="#flash" hx-confirm="Approve every payment of this run?" class="mb-2" id="payment-run-approve-form"><?= csrf_field() ?><input type="hidden" name="payment_run" value="<?= $id ?>">
                        <?php if ($needsCheques): ?><label class="form-label fs-12 text-muted" for="payment-run-first-cheque">First cheque number (the cheques are numbered from it in order)</label><input type="text" inputmode="numeric" name="first_cheque_number" id="payment-run-first-cheque" class="form-control btn-touch mb-2" maxlength="20"><?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="payment-run-approve-btn" <?= $run['payments'] === [] ? 'disabled' : '' ?>>Approve the run</button></form>
                <?php elseif ($st === 'draft'): ?><div class="fs-12 text-muted mb-2" id="payment-run-awaiting-note">Awaiting approval by the controller.</div><?php endif; ?>
                <?php if ($st === 'approved' && $may['write']): ?>
                    <form method="post" action="/payment-runs/record.php" hx-post="/payment-runs/record.php" hx-target="#flash" hx-confirm="Record every payment of this run as paid? Do this once the money has left the account." class="mb-2" id="payment-run-record-form"><?= csrf_field() ?><input type="hidden" name="payment_run" value="<?= $id ?>">
                        <label class="form-label fs-12 text-muted" for="payment-run-record-date">When did the money leave the account?</label><input type="date" name="pay_date" id="payment-run-record-date" class="form-control btn-touch mb-2" value="<?= e($run['pay_date']) ?>">
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="payment-run-record-btn">Record as paid</button></form>
                <?php endif; ?>
                <?php if ($may['override'] && !($st === 'recorded' && $run['void_count'] === 0 && $run['recorded_count'] === count($run['payments']))): ?>
                    <form method="post" action="/payment-runs/void.php" hx-post="/payment-runs/void.php" hx-target="#flash" hx-confirm="Void this run? Its unrecorded payments are voided; recorded ones stay." id="payment-run-void-form"><?= csrf_field() ?><input type="hidden" name="payment_run" value="<?= $id ?>">
                        <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is voided" maxlength="500" required id="payment-run-void-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="payment-run-void-btn">Void the run</button></form>
                <?php endif; ?>
            </div></div>
            <?php endif; ?>
            <?= view('shared/attachments.php', ['type' => 'payment_run', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'payment-run']) ?>
            <?= view('shared/notes.php', ['type' => 'payment_run', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'payment-run']) ?>
        </div>
        <div class="col-lg-7">
            <?= view('payment_runs/partials/run-payments.php', ['run' => $run, 'more' => $more, 'may' => $may, 'here' => $here]) ?>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'payment-run']) ?>
        </div>
    </div>
</div>

<?php /** One deposit (screen `deposit-view`). Data: d, more, attachments, notes, timeline, may, here, tz, notice */ $id = (int) $d['deposit_id']; $cur = $d['currency']; $draft = $d['status'] === 'draft'; ?>
<?= view('shared/header.php', ['id' => 'deposit-view', 'title' => $d['number'], 'crumbs' => [['Home', '/'], ['Deposits', '/deposits/'], [$d['number'], null]], 'back' => back_link() ?? ['/deposits/', 'deposits']]) ?>
<div class="main-content" id="deposit-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="deposit-head"><div class="card-body">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fs-20 fw-bold" id="deposit-head-total"><?= e(fmt_money($d['total'], $cur)) ?></div><span class="badge bg-<?= DEPOSIT_STATUS_BADGE[$d['status']] ?>" id="deposit-head-status"><?= e($d['status']) ?></span></div>
                <div class="fs-12 text-muted"><?= e($d['bank_account_name']) ?> · <?= e(format_date($d['deposit_date'])) ?></div>
                <?php if ($d['memo']): ?><div class="mt-1"><?= e($d['memo']) ?></div><?php endif; ?>
                <?php if ($d['journal_entry_id'] !== null): ?><div class="fs-12 mt-2">Posted as <?= hx_link(with_back('/journal/' . (int) $d['journal_entry_id'], $here), e((string) ($d['journal_entry_number'] ?? 'the entry')), '', 'id="deposit-head-entry-link"') ?></div><?php endif; ?>
                <div class="fs-12 mt-1" id="deposit-head-matched"><?= $d['matched_line'] !== null ? 'Matched to the bank line of ' . e(format_date($d['matched_line']['txn_date'])) : ($d['status'] === 'recorded' ? '<span class="text-muted">Not matched to a bank line yet.</span>' : '') ?></div>
                <?php if ($d['void_reason']): ?><div class="fs-12 text-muted mt-1" id="deposit-head-void">Void: <?= e($d['void_reason']) ?></div><?php endif; ?>
            </div></div>
            <?php if ($may['write'] && $draft): ?>
                <div class="card mb-3" id="deposit-actions"><div class="card-body">
                    <form method="post" action="/deposits/record.php" hx-post="/deposits/record.php" hx-target="#flash" hx-confirm="Record <?= e($d['number']) ?>? DR the bank, CR Undeposited funds."><?= csrf_field() ?><input type="hidden" name="deposit" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="deposit-record-btn" <?= $d['receipts'] === [] ? 'disabled' : '' ?>>Record the deposit</button></form>
                    <form method="post" action="/deposits/delete.php" hx-post="/deposits/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Delete this draft? Its receipts go back to undeposited."><?= csrf_field() ?><input type="hidden" name="deposit" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="deposit-delete-btn">Delete the draft</button></form>
                </div></div>
            <?php elseif ($may['override'] && $d['status'] === 'recorded'): ?>
                <div class="card mb-3" id="deposit-actions"><div class="card-body"><form method="post" action="/deposits/void.php" hx-post="/deposits/void.php" hx-target="#flash" hx-confirm="Void this deposit? Its entry is reversed and its receipts return to undeposited."><?= csrf_field() ?><input type="hidden" name="deposit" value="<?= $id ?>">
                    <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is voided" maxlength="500" required id="deposit-void-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="deposit-void-btn">Void</button></form></div></div>
            <?php endif; ?>
            <?= view('shared/attachments.php', ['type' => 'deposit', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'deposit']) ?>
            <?= view('shared/notes.php', ['type' => 'deposit', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'deposit']) ?>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="deposit-receipts"><div class="card-header"><h5 class="card-title mb-0">Receipts in it</h5></div>
                <?php if ($d['receipts'] === []): ?><div class="card-body text-muted" id="deposit-receipts-empty">Nothing in it yet.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($d['receipts'] as $r): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="deposit-receipt-<?= $r['receipt_id'] ?>"><span><?= hx_link(with_back('/receipts/' . $r['receipt_id'], $here), e($r['number'])) ?> <span class="fs-12 text-muted"><?= e($r['customer_name']) ?> · <?= e(format_date($r['received_on'])) ?></span></span>
                        <span class="d-flex align-items-center gap-2"><span><?= e(fmt_money($r['amount'], $cur, false)) ?></span>
                        <?php if ($may['write'] && $draft): ?><form method="post" action="/deposits/remove.php" hx-post="/deposits/remove.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="deposit" value="<?= $id ?>"><input type="hidden" name="receipt" value="<?= $r['receipt_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light" id="deposit-receipt-<?= $r['receipt_id'] ?>-remove-btn">Take out</button></form><?php endif; ?></span></div>
                <?php endforeach; ?></div><?php endif; ?>
                <?php if ($may['write'] && $draft && $more !== []): ?>
                <div class="card-body border-top"><form method="post" action="/deposits/add.php" hx-post="/deposits/add.php" hx-target="#flash" class="row g-2 align-items-end" id="deposit-add-form"><?= csrf_field() ?><input type="hidden" name="deposit" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                    <div class="col-8"><label class="form-label fs-12 text-muted" for="deposit-add-receipt">Add a receipt</label><select name="receipt" id="deposit-add-receipt" class="form-select btn-touch"><?php foreach ($more as $r): ?><option value="<?= $r['receipt_id'] ?>"><?= e($r['number']) ?> — <?= e($r['customer_name']) ?> <?= e(fmt_money($r['amount'], $r['currency'], false)) ?></option><?php endforeach; ?></select></div>
                    <div class="col-4"><button type="submit" class="btn btn-light btn-touch w-100" id="deposit-add-btn">Add</button></div></form></div>
                <?php endif; ?>
            </div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'deposit']) ?>
        </div>
    </div>
</div>

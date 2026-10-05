<?php /** One transfer (screen `transfer-view`). Data: t, attachments, notes, timeline, may, here, tz, notice */ $id = (int) $t['transfer_id']; $void = $t['status'] === 'void'; ?>
<?= view('shared/header.php', ['id' => 'transfer-view', 'title' => $t['number'], 'crumbs' => [['Home', '/'], ['Bank accounts', '/cash/'], ['Transfers', '/cash/transfers/'], [$t['number'], null]], 'back' => back_link() ?? ['/cash/transfers/', 'transfers']]) ?>
<div class="main-content" id="transfer-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3"><div class="col-lg-5">
        <div class="card mb-3" id="transfer-head"><div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2"><div class="fs-20 fw-bold" id="transfer-head-amount"><?= e(fmt_money($t['amount'], $t['currency'])) ?></div><span class="badge bg-<?= $void ? 'dark' : 'success' ?>" id="transfer-head-status"><?= $void ? 'Void' : 'Recorded' ?></span></div>
            <dl class="row mb-0 fs-12 mt-2">
                <dt class="col-5 text-muted">From</dt><dd class="col-7" id="transfer-head-from"><?= hx_link(with_back('/cash/banks/' . (int) $t['from_bank_account_id'], $here), e($t['from_bank_name'])) ?></dd>
                <dt class="col-5 text-muted">To</dt><dd class="col-7" id="transfer-head-to"><?= hx_link(with_back('/cash/banks/' . (int) $t['to_bank_account_id'], $here), e($t['to_bank_name'])) ?></dd>
                <dt class="col-5 text-muted">Date</dt><dd class="col-7"><?= e(format_date($t['transfer_date'])) ?></dd>
                <?php if ($t['memo']): ?><dt class="col-5 text-muted">Memo</dt><dd class="col-7"><?= e($t['memo']) ?></dd><?php endif; ?>
                <?php if ($t['journal_entry_id'] !== null): ?><dt class="col-5 text-muted">Entry</dt><dd class="col-7"><?= hx_link(with_back('/journal/' . (int) $t['journal_entry_id'], $here), e((string) ($t['journal_entry_number'] ?? 'the entry')), '', 'id="transfer-head-entry-link"') ?></dd><?php endif; ?>
                <?php if ($void): ?><dt class="col-5 text-muted">Void</dt><dd class="col-7" id="transfer-head-void"><?= e((string) $t['void_reason']) ?></dd><?php endif; ?>
            </dl>
        </div></div>
        <?php if (!$void && $may['override']): ?><div class="card mb-3" id="transfer-actions"><div class="card-body"><form method="post" action="/cash/transfers/void.php" hx-post="/cash/transfers/void.php" hx-target="#flash" hx-confirm="Void this transfer? Its entry is reversed."><?= csrf_field() ?><input type="hidden" name="transfer" value="<?= $id ?>">
            <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is voided" maxlength="500" required id="transfer-void-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="transfer-void-btn">Void</button></form></div></div><?php endif; ?>
        <?= view('shared/attachments.php', ['type' => 'transfer', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'transfer']) ?>
        <?= view('shared/notes.php', ['type' => 'transfer', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'transfer']) ?>
    </div><div class="col-lg-7">
        <div class="card mb-3" id="transfer-matched"><div class="card-header"><h5 class="card-title mb-0">Bank lines it matched</h5></div>
            <?php if ($t['matched_lines'] === []): ?><div class="card-body text-muted" id="transfer-matched-empty">Neither bank line has been matched yet.<?= !$void ? ' ' . hx_link('/cash/match?bank_account=' . (int) $t['from_bank_account_id'], 'Match the sending account') . ' · ' . hx_link('/cash/match?bank_account=' . (int) $t['to_bank_account_id'], 'the receiving account') : '' ?></div><?php else: ?>
            <div class="list-group list-group-flush"><?php foreach ($t['matched_lines'] as $m): ?><div class="list-group-item d-flex justify-content-between" id="transfer-matched-<?= $m['bank_transaction_id'] ?>"><span><?= hx_link(with_back('/cash/banks/' . $m['bank_account_id'], $here), e($m['bank_account_name'])) ?> <span class="fs-12 text-muted"><?= e(format_date($m['txn_date'])) ?></span></span><span class="<?= m_cmp((string) $m['amount'], '0') < 0 ? 'text-danger' : 'text-success' ?>"><?= e(fmt_money($m['amount'], $t['currency'], false)) ?></span></div><?php endforeach; ?></div><?php endif; ?>
        </div>
        <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'transfer']) ?>
    </div></div>
</div>

<?php /** One bank account as a card. Data: b, may, here */ $id = (int) $b['bank_account_id']; $cur = $b['currency']; $diff = m_cmp((string) $b['difference'], '0') !== 0; ?>
<div class="card h-100<?= $b['archived_at'] !== null ? ' opacity-75' : '' ?>" id="bank-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0" id="bank-card-<?= $id ?>-name"><?= hx_link(with_back('/cash/banks/' . $id, $here), e($b['name']), 'text-dark') ?></div>
        <span class="d-flex gap-1"><span class="badge bg-soft-secondary text-secondary" id="bank-card-<?= $id ?>-kind"><?= e(BANK_KINDS[$b['kind']] ?? $b['kind']) ?></span><?php if ($b['archived_at'] !== null): ?><span class="badge bg-soft-danger text-danger">Archived</span><?php endif; ?></span>
    </div>
    <div class="fs-12 text-muted" id="bank-card-<?= $id ?>-facts"><?= e(trim(($b['institution'] ?? '') . ($b['account_last4'] ? ' · ' . $b['account_last4'] : ''))) ?> <?= $b['institution'] || $b['account_last4'] ? '·' : '' ?> ledger <?= e($b['account_code']) ?> · <?= e($cur) ?></div>
    <dl class="row mb-0 mt-2 fs-12">
        <dt class="col-6 text-muted">Ledger balance</dt><dd class="col-6 text-end fw-semibold" id="bank-card-<?= $id ?>-ledger"><?= e(fmt_money($b['ledger_balance'], $cur)) ?></dd>
        <dt class="col-6 text-muted">Cleared balance</dt><dd class="col-6 text-end" id="bank-card-<?= $id ?>-cleared"><?= e(fmt_money($b['cleared_balance'], $cur)) ?></dd>
        <dt class="col-6 text-muted">Difference</dt><dd class="col-6 text-end <?= $diff ? 'text-warning' : 'text-success' ?>" id="bank-card-<?= $id ?>-difference"><?= e(fmt_money($b['difference'], $cur)) ?></dd>
        <dt class="col-6 text-muted">Reconciled through</dt><dd class="col-6 text-end" id="bank-card-<?= $id ?>-reconciled"><?= $b['last_reconciled_through'] ? e(format_date($b['last_reconciled_through'])) . ' <span class="text-muted">· ' . e(fmt_money($b['last_reconciled_balance'], $cur, false)) . '</span>' : 'never' ?></dd>
    </dl>
    <div class="mt-2 d-flex flex-wrap gap-1">
        <?php if ($b['unmatched_lines'] > 0): ?><?= hx_link(with_back('/cash/match?bank_account=' . $id, $here), '<span class="badge bg-warning text-dark">' . (int) $b['unmatched_lines'] . '</span> to match', 'btn btn-sm btn-light', 'id="bank-card-' . $id . '-unmatched-link"') ?><?php else: ?><span class="fs-12 text-muted" id="bank-card-<?= $id ?>-nothing">Nothing to match</span><?php endif; ?>
        <?php if ($b['reconciliation_in_progress_id'] !== null): ?><?= hx_link(with_back('/cash/reconcile?bank_account=' . $id, $here), '<i class="feather-clock me-1"></i>Reconciling', 'btn btn-sm btn-soft-info', 'id="bank-card-' . $id . '-recon-chip"') ?><?php endif; ?>
    </div>
    <?php if ($may['write'] && $b['archived_at'] === null): ?>
    <div class="row g-2 mt-2">
        <div class="col-4"><?= hx_link(with_back('/cash/import?bank_account=' . $id, $here), 'Import', 'btn btn-light btn-touch w-100', 'id="bank-card-' . $id . '-import-btn"') ?></div>
        <div class="col-4"><?= hx_link(with_back('/cash/match?bank_account=' . $id, $here), 'Match', 'btn btn-light btn-touch w-100', 'id="bank-card-' . $id . '-match-btn"') ?></div>
        <div class="col-4"><?= hx_link(with_back('/cash/reconcile?bank_account=' . $id, $here), 'Reconcile', 'btn btn-light btn-touch w-100', 'id="bank-card-' . $id . '-reconcile-btn"') ?></div>
    </div>
    <?php endif; ?>
</div></div>

<?php /** The lines a reconciliation covers: matched ✓, ignored, unmatched in red with a link to match. Data: rec, lines, counts, bank, here, may */ $cur = $bank['currency'] ?? 'USD'; ?>
<div class="card mb-3" id="reconcile-lines"><div class="card-header"><h5 class="card-title mb-0">Lines through <?= e(format_date($rec['statement_date'])) ?></h5><span class="fs-12 text-muted" id="reconcile-counts"><?= (int) $counts['matched'] ?> matched · <?= (int) $counts['ignored'] ?> ignored · <span class="<?= $counts['unmatched'] > 0 ? 'text-danger fw-semibold' : '' ?>"><?= (int) $counts['unmatched'] ?> unmatched</span></span></div>
    <?php if ($lines === []): ?><div class="card-body text-muted" id="reconcile-lines-empty">No line.</div><?php else: ?>
    <div class="list-group list-group-flush"><?php foreach ($lines as $l): $lid = (int) $l['bank_transaction_id']; $bad = $l['status'] === 'unmatched'; ?>
        <div class="list-group-item d-flex justify-content-between align-items-center gap-2 <?= $bad ? 'bg-soft-danger' : '' ?>" id="reconcile-line-<?= $lid ?>">
            <span class="min-w-0"><span class="<?= $bad ? 'text-danger fw-semibold' : ($l['status'] === 'matched' ? 'text-success' : 'text-muted') ?>"><?= $bad ? '●' : ($l['status'] === 'matched' ? '✓' : '–') ?></span> <?= e(format_date($l['txn_date'])) ?> <span class="text-truncate"><?= e($l['description']) ?></span><?php if ($l['status'] === 'ignored'): ?><span class="fs-12 text-muted"> · ignored: <?= e((string) $l['ignore_reason']) ?></span><?php endif; ?>
                <?php if ($bad && $may['write']): ?> <?= hx_link(with_back('/cash/match?bank_account=' . (int) $l['bank_account_id'], $here), 'Match it', 'fs-12', 'id="reconcile-line-' . $lid . '-match-link"') ?><?php endif; ?></span>
            <span class="text-nowrap <?= m_cmp((string) $l['amount'], '0') < 0 ? 'text-danger' : 'text-success' ?>"><?= e(fmt_money($l['amount'], $cur, false)) ?></span>
        </div>
    <?php endforeach; ?></div><?php endif; ?>
</div>

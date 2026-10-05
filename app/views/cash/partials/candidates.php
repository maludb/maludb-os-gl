<?php /** The candidates of one bank line, each with a Match button. Data: line, candidates */ $id = (int) $line['bank_transaction_id']; ?>
<?php if ($candidates === []): ?>
    <div class="fs-12 text-muted" id="bank-line-<?= $id ?>-nocandidate"><?= m_cmp((string) $line['amount'], '0') > 0 ? 'No receipt, deposit or entry of exactly this amount within five days. Record the receipt (or make the deposit) first — or make an entry for it below.' : 'No payment or entry of exactly this amount within five days. Record the payment first — or make an entry for it below (a fee, say).' ?></div>
<?php else: ?>
    <div class="list-group list-group-flush border rounded" id="bank-line-<?= $id ?>-candidates"><?php foreach ($candidates as $c): ?>
        <form method="post" action="/cash/match.php" hx-post="/cash/match.php" hx-target="#bank-line-<?= $id ?>" hx-swap="outerHTML" class="list-group-item d-flex align-items-center gap-2 bank-candidate" id="bank-line-<?= $id ?>-candidate-<?= e($c['item_type']) ?>-<?= $c['item_id'] ?>" <?= count($candidates) === 1 ? 'data-sure="1"' : '' ?>>
            <?= csrf_field() ?><input type="hidden" name="bank_line" value="<?= $id ?>"><input type="hidden" name="item_type" value="<?= e($c['item_type']) ?>"><input type="hidden" name="item" value="<?= (int) $c['item_id'] ?>">
            <span class="flex-grow-1 min-w-0"><span class="badge bg-soft-secondary text-secondary me-1"><?= e(MATCH_TYPES[$c['item_type']] ?? $c['item_type']) ?></span><span class="fw-semibold"><?= e((string) $c['number']) ?></span> <span class="fs-12 text-muted"><?= e(format_date($c['item_date'])) ?><?= $c['days_apart'] > 0 ? ' · ' . $c['days_apart'] . ' day' . ($c['days_apart'] === 1 ? '' : 's') . ' apart' : ' · same day' ?></span><span class="d-block fs-12 text-muted text-truncate"><?= e((string) $c['description']) ?></span></span>
            <button type="submit" class="btn btn-primary btn-touch" id="bank-line-<?= $id ?>-match-<?= e($c['item_type']) ?>-<?= $c['item_id'] ?>-btn">Match</button>
        </form>
    <?php endforeach; ?></div>
<?php endif; ?>

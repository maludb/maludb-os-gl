<?php /** One unmatched bank line as a card with its candidates, the entry form and Ignore. Data: line, candidates, may, pdo, departments, sites, here */ $id = (int) $line['bank_transaction_id']; $neg = m_cmp((string) $line['amount'], '0') < 0; ?>
<div class="card mb-3" id="bank-line-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0"><div class="fw-semibold" id="bank-line-<?= $id ?>-description"><?= e($line['description']) ?></div><div class="fs-12 text-muted"><?= e(format_date($line['txn_date'])) ?><?= $line['external_ref'] && !str_starts_with((string) $line['external_ref'], 'h:') ? ' · ' . e($line['external_ref']) : '' ?></div></div>
        <div class="fw-bold fs-16 text-nowrap <?= $neg ? 'text-danger' : 'text-success' ?>" id="bank-line-<?= $id ?>-amount"><?= e(fmt_money($line['amount'], 'USD', false)) ?></div>
    </div>
    <div class="mt-2"><?= view('cash/partials/candidates.php', ['line' => $line, 'candidates' => $candidates]) ?></div>
    <?php if ($may['write']): ?>
    <div class="mt-2 d-flex flex-wrap gap-2">
        <?php if ($may['entry']): ?><details class="w-100 border rounded p-2" id="bank-line-<?= $id ?>-entry"><summary class="fs-12 fw-semibold" style="min-height:44px;display:flex;align-items:center">Make an entry (a fee, interest…)</summary>
            <form method="post" action="/cash/entry.php" hx-post="/cash/entry.php" hx-target="#bank-line-<?= $id ?>" hx-swap="outerHTML" hx-confirm="Post an entry for this bank line and match it?" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="bank_line" value="<?= $id ?>">
                <div class="col-12 col-md-5"><?= account_select($pdo, 'account', null, $neg ? ['expense'] : ['income', 'expense'], [], 'required', 'Choose an account…', 'bank-line-' . $id . '-entry-account') ?></div>
                <div class="col-12 col-md-5"><input type="text" name="memo" class="form-control btn-touch" maxlength="300" placeholder="Memo (empty: the line's description)" id="bank-line-<?= $id ?>-entry-memo"></div>
                <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="bank-line-<?= $id ?>-entry-btn">Post</button></div></form></details><?php endif; ?>
        <details class="w-100 border rounded p-2" id="bank-line-<?= $id ?>-ignore"><summary class="fs-12 fw-semibold" style="min-height:44px;display:flex;align-items:center">Ignore this line</summary>
            <form method="post" action="/cash/ignore.php" hx-post="/cash/ignore.php" hx-target="#bank-line-<?= $id ?>" hx-swap="outerHTML" hx-confirm="Ignore this bank line?" class="row g-2 mt-1"><?= csrf_field() ?><input type="hidden" name="bank_line" value="<?= $id ?>">
                <div class="col-12 col-md-10"><input type="text" name="reason" class="form-control btn-touch" maxlength="500" required placeholder="Why it is ignored (a duplicate, a personal item…)" id="bank-line-<?= $id ?>-ignore-reason"></div>
                <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="bank-line-<?= $id ?>-ignore-btn">Ignore</button></div></form></details>
    </div>
    <?php endif; ?>
</div></div>

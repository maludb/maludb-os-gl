<?php /** A bank account's figures (the ledger, the bank, the difference, what waits). Data: bank, unmatched?, oob? */ $u = $unmatched ?? $bank['unmatched_lines']; $cur = $bank['currency']; ?>
<div id="bank-figures" <?= !empty($oob) ? 'hx-swap-oob="true"' : '' ?> class="card mb-3"><div class="card-body p-3"><div class="row g-2 fs-12 text-center">
    <div class="col-6 col-md-3"><div class="text-muted">Ledger balance</div><div class="fw-bold fs-14" id="bank-figures-ledger"><?= e(fmt_money($bank['ledger_balance'], $cur)) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted">Cleared (matched)</div><div class="fw-bold fs-14" id="bank-figures-cleared"><?= e(fmt_money($bank['cleared_balance'], $cur)) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted">Difference</div><div class="fw-bold fs-14 <?= m_cmp((string) $bank['difference'], '0') === 0 ? 'text-success' : 'text-warning' ?>" id="bank-figures-difference"><?= e(fmt_money($bank['difference'], $cur)) ?></div></div>
    <div class="col-6 col-md-3"><div class="text-muted">Lines to match</div><div class="fw-bold fs-14" id="bank-figures-unmatched"><?= (int) $u ?></div></div>
</div></div></div>

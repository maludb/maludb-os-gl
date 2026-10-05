<?php /** The import preview: the detected columns, five rows, the errors. Data: preview, values */ $p = $preview; $cols = $p['columns']; ?>
<div class="card mb-3" id="import-preview"><div class="card-header"><h5 class="card-title mb-0">Preview</h5><span class="fs-12 text-muted" id="import-preview-count"><?= (int) $p['total_rows'] ?> row<?= $p['total_rows'] === 1 ? '' : 's' ?> in the file</span></div>
    <div class="card-body">
        <div class="fs-12 mb-2" id="import-preview-columns"><span class="text-muted">Read as:</span>
            <?php foreach (['date' => 'date', 'amount' => 'amount', 'debit' => 'debit', 'credit' => 'credit', 'description' => 'description', 'reference' => 'reference', 'balance' => 'balance'] as $k => $label): if (($cols[$k] ?? null) === null) { continue; } ?><span class="badge bg-soft-primary text-primary me-1"><?= e($label) ?>: <?= e((string) $cols[$k]) ?></span><?php endforeach; ?></div>
        <?php if ($p['errors'] !== []): ?>
            <div class="alert alert-danger fs-12" id="import-preview-errors"><div class="fw-semibold mb-1">Nothing can be imported until this is fixed:</div><ul class="mb-0"><?php foreach (array_slice($p['errors'], 0, 8) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?><?php if (count($p['errors']) > 8): ?><li>… and <?= count($p['errors']) - 8 ?> more</li><?php endif; ?></ul></div>
        <?php endif; ?>
        <?php if ($p['hashed'] > 0): ?><div class="alert alert-info fs-12" id="import-preview-hashed">The bank gave no reference on <?= (int) $p['hashed'] ?> row<?= $p['hashed'] === 1 ? '' : 's' ?>. Each is given a hash of its date, amount and description, plus its place among identical lines in the file — so importing the same file twice skips what is already here, and two equal fees on one day stay two lines.</div><?php endif; ?>
    </div>
    <?php if ($p['rows'] !== []): ?><div class="table-responsive"><table class="table table-sm mb-0" id="import-preview-table"><thead><tr><th>Date</th><th>Description</th><th class="text-end">Amount</th><th>Reference</th></tr></thead><tbody>
        <?php foreach ($p['rows'] as $r): ?><tr><td class="text-nowrap"><?= e(format_date($r['txn_date'])) ?></td><td><?= e($r['description']) ?></td><td class="text-end text-nowrap <?= m_cmp((string) $r['amount'], '0') < 0 ? 'text-danger' : 'text-success' ?>"><?= e(fmt_money($r['amount'], 'USD', false)) ?></td><td class="fs-12 text-muted"><?= $r['hashed'] ? '(hash)' : e((string) $r['reference']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
</div>

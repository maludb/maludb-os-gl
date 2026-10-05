<?php /** Import a statement (screen `bank-import`). Data: banks, bankId, preview, csv?, filename?, values (date_format, amount_sign, columns?), here */
$cols = $values['columns'] ?? []; $header = $preview['header'] ?? []; $csv = $csv ?? null; ?>
<?= view('shared/header.php', ['id' => 'bank-import', 'title' => 'Import a statement', 'crumbs' => [['Home', '/'], ['Bank accounts', '/cash/'], ['Import', null]], 'back' => back_link() ?? ['/cash/', 'bank accounts']]) ?>
<div class="main-content" id="bank-import-content">
    <?php if ($preview !== null): ?><?= view('cash/partials/import-preview.php', ['preview' => $preview, 'values' => $values]) ?><?php endif; ?>
    <form method="post" action="/cash/import.php" enctype="multipart/form-data" id="bank-import-form" class="card mb-3">
        <?= csrf_field() ?><div class="card-header"><h5 class="card-title mb-0"><?= $preview === null ? 'The statement file' : 'Adjust and import' ?></h5></div><div class="card-body">
        <label class="form-label fs-12 text-muted" for="bank-import-bank">Bank account</label>
        <select name="bank_account" id="bank-import-bank" class="form-select btn-touch mb-3" required><option value="">Choose a bank account…</option><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>" <?= $bankId === $b['bank_account_id'] ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['currency']) ?>)</option><?php endforeach; ?></select>
        <?php if ($csv === null): ?>
            <label class="form-label fs-12 text-muted" for="bank-import-file">CSV file (read in memory, never stored)</label>
            <input type="file" name="file" id="bank-import-file" class="form-control btn-touch mb-3" accept=".csv,text/csv,text/plain" required>
        <?php else: ?>
            <div class="fs-12 text-muted mb-3" id="bank-import-held">Importing <span class="fw-semibold"><?= e((string) ($filename ?? 'the file')) ?></span> — the page holds its text; nothing is stored on the server.</div>
            <textarea name="csv" class="d-none" id="bank-import-csv"><?= e($csv) ?></textarea><input type="hidden" name="filename" value="<?= e((string) ($filename ?? '')) ?>">
        <?php endif; ?>
        <div class="row g-2 mb-3">
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bank-import-dateformat">Date format</label><select name="date_format" id="bank-import-dateformat" class="form-select btn-touch"><?php foreach (BANK_DATE_FORMATS as $k => $label): ?><option value="<?= e($k) ?>" <?= ($values['date_format'] ?? 'ymd') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bank-import-sign">Amounts</label><select name="amount_sign" id="bank-import-sign" class="form-select btn-touch"><?php foreach (BANK_AMOUNT_SIGNS as $k => $label): ?><option value="<?= e($k) ?>" <?= ($values['amount_sign'] ?? 'signed') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        </div>
        <?php if ($header !== []): ?>
        <div class="fs-12 text-muted mb-1">Columns (from the file's first row; change one if it was read wrongly)</div>
        <div class="row g-2 mb-3" id="bank-import-columns"><?php foreach (['date' => 'Date', 'amount' => 'Amount', 'debit' => 'Debit', 'credit' => 'Credit', 'description' => 'Description', 'reference' => 'Reference'] as $k => $label): $sel = $cols[$k] ?? ($preview['columns'][$k] ?? null); ?>
            <div class="col-6 col-md-4"><label class="form-label fs-12 text-muted" for="bank-import-col-<?= $k ?>"><?= $label ?></label><select name="columns[<?= $k ?>]" id="bank-import-col-<?= $k ?>" class="form-select btn-touch"><option value="">— none —</option><?php foreach ($header as $i => $h): ?><option value="<?= (int) $i ?>" <?= ((string) $sel === (string) $h || (string) $sel === (string) $i) ? 'selected' : '' ?>><?= e($h) ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?></div>
        <?php endif; ?>
        <div class="row g-2">
            <div class="col-12 col-md-6"><button type="submit" name="preview" value="1" class="btn btn-light btn-touch w-100" id="bank-import-preview-btn"><?= $preview === null ? 'Preview' : 'Preview again' ?></button></div>
            <div class="col-12 col-md-6"><button type="submit" class="btn btn-primary btn-touch w-100" id="bank-import-btn" <?= $preview === null || $preview['errors'] !== [] || $preview['rows'] === [] ? 'disabled' : '' ?>>Import</button></div>
        </div>
        <div class="fs-12 text-muted mt-2">The first five rows are shown before anything is written. A line already here (same reference) is skipped and counted.</div>
    </div></form>
</div>

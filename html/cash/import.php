<?php
declare(strict_types=1);
/**
 * GET /cash/import — the import form (screen `bank-import`; bank.write). Param: bank_account.
 * POST — action `bank_import` (bank_account, file as multipart CSV or `csv` text, date_format ymd|dmy|mdy, amount_sign signed|debit_credit, columns as JSON or columns[date]… — empty detects from the header; `preview=1` parses and answers
 * the first five rows without writing; log `bank.import` with import_id, rows, inserted, skipped, hashed and bank_account_id): bank.write. The file is read in memory and never stored; a duplicate (same account, same reference) is skipped and counted;
 * a bank that gives no reference gets a hash of date + amount + description + a running index per file, and the answer says so.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/cash/handler.php';
const IMPORT_MAX_BYTES = 2097152;
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($post) {
    gl_handler_begin();
} else {
    require_login();
}
require_right('bank.write');
$pdo = db();
$banks = bank_accounts_for_select($pdo);
if (!$post) {
    $bankId = request_integer('bank_account');
    log_screen_view($pdo, 'bank-import');
    if (wants_json()) {
        respond_screen(['banks' => $banks, 'bank_account_id' => $bankId, 'date_formats' => array_keys(BANK_DATE_FORMATS), 'amount_signs' => array_keys(BANK_AMOUNT_SIGNS)]);
    }
    render_screen('Import a statement', view('cash/import.php', ['banks' => $banks, 'bankId' => $bankId, 'preview' => null, 'values' => ['date_format' => 'ymd', 'amount_sign' => 'signed'], 'here' => here_url()]), ['activeNav' => 'banks', 'screen' => 'bank-import', 'entity' => 'bankAccount']);
    exit;
}
$errors = [];
$bankId = gl_ref($pdo, req_has('bank_account') ? 'bank_account' : 'bank_account_id', null, 'SELECT 1 FROM bank_accounts WHERE id = :id AND archived_at IS NULL', 'bank account', $errors, false);
$dateFormat = (string) req_val('date_format') === '' ? 'ymd' : (string) req_val('date_format');
if (!isset(BANK_DATE_FORMATS[$dateFormat])) {
    $errors['date_format'] = 'The date format is ymd, dmy or mdy.';
}
$amountSign = (string) req_val('amount_sign');
if ($amountSign !== '' && !isset(BANK_AMOUNT_SIGNS[$amountSign])) {
    $errors['amount_sign'] = 'The amount sign is signed or debit_credit.';
}
$map = [];
$rawCols = $_POST['columns'] ?? null;
if (is_string($rawCols) && trim($rawCols) !== '') {
    $map = json_decode($rawCols, true);
    if (!is_array($map)) {
        $errors['columns'] = 'columns is a JSON object naming the date, amount (or debit and credit), description and reference columns.';
        $map = [];
    }
} elseif (is_array($rawCols)) {
    $map = $rawCols;
}
$map = array_intersect_key($map, array_flip(['date', 'amount', 'debit', 'credit', 'description', 'reference', 'balance']));
$contents = null;
$filename = null;
if (isset($_FILES['file']) && (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['file'];
    if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name']) || (int) $f['size'] > IMPORT_MAX_BYTES) {
        $errors['file'] = (int) $f['size'] > IMPORT_MAX_BYTES || (int) $f['error'] === UPLOAD_ERR_INI_SIZE ? 'The file is larger than 2 MB.' : 'The upload did not arrive whole; try again.';
    } else {
        $contents = (string) file_get_contents((string) $f['tmp_name']);              // read in memory; the temporary file goes with the request
        $filename = basename((string) $f['name']);
    }
} elseif (req_has('csv') && (string) req_val('csv') !== '') {
    $contents = (string) req_val('csv');
    $filename = req_has('filename') ? mb_substr((string) req_val('filename'), 0, 200) : null;
    if (strlen($contents) > IMPORT_MAX_BYTES) {
        $errors['csv'] = 'The statement is larger than 2 MB.';
    }
} else {
    $errors['file'] = 'Choose the statement file (CSV).';
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$parsed = parse_bank_csv((string) $contents, $map, $dateFormat, $amountSign);
$preview = ['rows' => array_slice($parsed['rows'], 0, 5), 'errors' => $parsed['errors'], 'header' => $parsed['header'], 'columns' => $parsed['columns'], 'amount_sign' => $parsed['amount_sign'], 'total_rows' => $parsed['total_rows'], 'hashed' => $parsed['hashed']];
if (req_has('preview') && gl_yes('preview', false)) {
    if (wants_json()) {
        emit_action_status(true, ['did' => 'Previewed ' . $parsed['total_rows'] . ' row(s); nothing was written']);
        respond_saved(['did' => 'Previewed ' . $parsed['total_rows'] . ' row(s); nothing was written', 'record_id' => null, 'location' => '/cash/import?bank_account=' . $bankId, 'preview' => $preview + ['hashed_note' => $parsed['hashed'] > 0 ? 'The bank gave no reference on ' . $parsed['hashed'] . ' row(s): each was given a hash of its date, amount, description and its place among identical lines in the file.' : null]]);
    }
    log_screen_view($pdo, 'bank-import');
    render_screen('Import a statement', view('cash/import.php', ['banks' => $banks, 'bankId' => $bankId, 'preview' => $preview, 'csv' => (string) $contents, 'filename' => $filename, 'values' => ['date_format' => $dateFormat, 'amount_sign' => $parsed['amount_sign'], 'columns' => $map], 'here' => here_url()]),
        ['activeNav' => 'banks', 'screen' => 'bank-import', 'entity' => 'bankAccount']);
    exit;
}
if ($parsed['errors'] !== []) {
    $shown = array_slice($parsed['errors'], 0, 8);
    gl_refuse_fields(['file' => implode(' ', $shown) . (count($parsed['errors']) > 8 ? ' … and ' . (count($parsed['errors']) - 8) . ' more.' : '') . ' Nothing was imported.']);
}
if ($parsed['rows'] === []) {
    gl_refuse_fields(['file' => 'The file holds no lines to import.']);
}
try {
    $pdo->beginTransaction();
    $r = import_bank_lines($pdo, $bankId, $parsed['rows'], $filename, (int) current_member_id(), $parsed['hashed']);
    log_activity($pdo, 'bank.import', 'bank_account', $bankId, bank_keys($bankId) + ['after' => ['import_id' => $r['import_id'], 'rows' => $r['rows'], 'inserted' => $r['inserted'], 'skipped' => $r['skipped'], 'hashed' => $r['hashed'], 'filename' => $filename]]);
    $pdo->commit();
} catch (Throwable $ex) {
    cash_refused($pdo, $ex);
}
gl_done('Imported ' . $r['inserted'] . ' of ' . $r['rows'] . ' line' . ($r['rows'] === 1 ? '' : 's') . ($r['skipped'] > 0 ? ' (' . $r['skipped'] . ' already here)' : '') . ($r['hashed'] > 0 ? '; the bank gave no reference on ' . $r['hashed'] . ', so each was given a hash of its date, amount and description' : ''),
    $r['import_id'], gl_land(return_path('/cash/match?bank_account=' . $bankId), 'imported'), 'bankChanged', $r + ['bank_account_id' => $bankId]);

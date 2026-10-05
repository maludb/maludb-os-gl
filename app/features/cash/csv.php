<?php
declare(strict_types=1);

/**
 * The bank's CSV, pure (slice 6; no database, no file, no globals): `parse_bank_csv()` reads the text of a statement under a column map, a date format and an amount convention and answers the rows
 * (txn_date, amount signed — + in, − out, description, reference, external_ref, balance_after) and the errors, each naming its row. `detect_columns()` finds the columns from the header's words.
 * The file is never stored: the handler holds the text in memory.
 */

const BANK_DATE_FORMATS = ['ymd' => 'Year-month-day (2026-06-30)', 'dmy' => 'Day-month-year (30/06/2026)', 'mdy' => 'Month-day-year (06/30/2026)'];
const BANK_AMOUNT_SIGNS = ['signed' => 'One amount column, money out is negative', 'debit_credit' => 'A debit column (out) and a credit column (in)'];
const BANK_COLUMN_WORDS = [
    'date'        => ['date', 'transaction date', 'posting date', 'posted', 'posted date', 'trans date', 'value date', 'booking date'],
    'amount'      => ['amount', 'amt', 'transaction amount', 'value'],
    'debit'       => ['debit', 'debits', 'withdrawal', 'withdrawals', 'money out', 'paid out', 'out'],
    'credit'      => ['credit', 'credits', 'deposit', 'deposits', 'money in', 'paid in', 'in'],
    'description' => ['description', 'details', 'memo', 'narrative', 'payee', 'particulars', 'transaction', 'name', 'text'],
    'reference'   => ['reference', 'ref', 'reference number', 'ref no', 'id', 'transaction id', 'check number', 'cheque number', 'check no', 'fitid'],
    'balance'     => ['balance', 'running balance', 'balance after', 'closing balance'],
];

/** The text split into rows of cells (quotes, doubled quotes and embedded newlines honoured; a byte-order mark dropped; wholly blank rows skipped). */
function bank_csv_cells(string $contents): array
{
    $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, str_replace(["\r\n", "\r"], "\n", $contents));
    rewind($stream);
    $rows = [];
    while (($r = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if ($r === [null] || implode('', array_map('trim', array_map('strval', $r))) === '') {
            continue;
        }
        $rows[] = array_map(static fn ($c): string => trim((string) $c), $r);
    }
    fclose($stream);
    return $rows;
}

/** Which column is which, from the header's words: ['date' => 0, 'amount' => 3, 'debit' => null, …, 'amount_sign' => 'signed'|'debit_credit']. A column named by a word is never claimed twice. */
function detect_columns(array $header): array
{
    $norm = array_map(static fn ($h): string => strtolower(trim(preg_replace('/\s+/', ' ', (string) $h) ?? '')), $header);
    $found = ['date' => null, 'amount' => null, 'debit' => null, 'credit' => null, 'description' => null, 'reference' => null, 'balance' => null];
    $taken = [];
    foreach (['date', 'amount', 'debit', 'credit', 'balance', 'reference', 'description'] as $key) {
        foreach (BANK_COLUMN_WORDS[$key] as $word) {                    // exact words first, in the list's order
            $i = array_search($word, $norm, true);
            if ($i !== false && !in_array($i, $taken, true)) {
                $found[$key] = (int) $i;
                $taken[] = (int) $i;
                break;
            }
        }
    }
    foreach (['date', 'description', 'reference'] as $key) {            // then a header that merely contains the word ("Transaction Description")
        if ($found[$key] === null) {
            foreach ($norm as $i => $h) {
                if (!in_array($i, $taken, true) && $h !== '' && str_contains($h, $key === 'description' ? 'descri' : $key)) {
                    $found[$key] = $i;
                    $taken[] = $i;
                    break;
                }
            }
        }
    }
    $found['amount_sign'] = $found['amount'] === null && $found['debit'] !== null && $found['credit'] !== null ? 'debit_credit' : 'signed';
    return $found;
}

/** A date in the stated order to Y-m-d, or null; a time after the date is ignored; a two-digit year is 20yy. */
function parse_bank_date(string $raw, string $format): ?string
{
    $s = trim(preg_split('/[ T]/', trim($raw))[0] ?? '');
    if (!preg_match('/^(\d{1,4})[-\/.](\d{1,2})[-\/.](\d{1,4})$/', $s, $m)) {
        return null;
    }
    [$a, $b, $c] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    [$y, $mo, $d] = match ($format) { 'dmy' => [$c, $b, $a], 'mdy' => [$c, $a, $b], default => [$a, $b, $c] };
    if ($y < 100) {
        $y += 2000;
    }
    return checkdate($mo, $d, $y) && $y >= 1990 && $y <= 2100 ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** An amount to a signed string with two decimals, or null: thousands commas, a currency sign, a leading + or −, parentheses (negative), CR/DR suffixes are understood; a third decimal is not an amount. */
function parse_bank_amount(string $raw): ?string
{
    $s = trim($raw);
    if ($s === '') {
        return null;
    }
    $neg = false;
    if (preg_match('/^\((.*)\)$/', $s, $m)) {
        $neg = true;
        $s = $m[1];
    }
    if (preg_match('/^(.*?)\s*(CR|DR)$/i', $s, $m)) {
        $neg = $neg || strtoupper($m[2]) === 'DR';
        $s = $m[1];
    }
    $s = str_replace([',', ' ', '$', '€', '£', "\u{00a0}"], '', $s);
    if (str_starts_with($s, '-') || str_starts_with($s, "\u{2212}")) {
        $neg = !$neg;
        $s = substr($s, str_starts_with($s, '-') ? 1 : strlen("\u{2212}"));
    } elseif (str_starts_with($s, '+')) {
        $s = substr($s, 1);
    }
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $s, $m)) {
        return null;
    }
    $int = ltrim($m[1], '0');
    $v = ($int === '' ? '0' : $int) . '.' . str_pad($m[2] ?? '', 2, '0');
    return $neg && $v !== '0.00' ? '-' . $v : $v;
}

/** One cell of a row by a column given as a 0-based index or a header name; null when the column is not given or the row is short. */
function bank_cell(array $row, array $header, mixed $col): ?string
{
    if ($col === null || $col === '') {
        return null;
    }
    $i = is_int($col) || (is_string($col) && ctype_digit($col)) ? (int) $col : array_search(strtolower(trim((string) $col)), array_map(static fn ($h): string => strtolower(trim((string) $h)), $header), true);
    return $i === false || !isset($row[$i]) ? null : $row[$i];
}

/**
 * Read a statement. $map: date, amount, debit, credit, description, reference, balance — each a header name or a 0-based index; empty → detected from the header. $amountSign: signed | debit_credit.
 * Answers: rows[] (txn_date, amount, description, reference, external_ref, balance_after, hashed), errors[] ("Row 3: …"), header[], columns (the resolved map by header name), amount_sign, total_rows, hashed (how many rows
 * got a hash for their reference because the bank gave none). A first row whose date cell is not a date is the header. Nothing is imported when any row has an error.
 */
function parse_bank_csv(string $contents, array $map, string $dateFormat, string $amountSign): array
{
    $dateFormat = isset(BANK_DATE_FORMATS[$dateFormat]) ? $dateFormat : 'ymd';
    $cells = bank_csv_cells($contents);
    $out = ['rows' => [], 'errors' => [], 'header' => [], 'columns' => [], 'amount_sign' => $amountSign, 'total_rows' => 0, 'hashed' => 0];
    if ($cells === []) {
        $out['errors'][] = 'The file is empty.';
        return $out;
    }
    $header = $cells[0];
    $detected = detect_columns($header);
    $use = array_filter($map, static fn ($v): bool => $v !== null && $v !== '');
    if ($use === []) {
        $use = array_filter(array_intersect_key($detected, array_flip(['date', 'amount', 'debit', 'credit', 'description', 'reference', 'balance'])), static fn ($v): bool => $v !== null);
        $amountSign = $detected['amount_sign'];
    }
    $out['amount_sign'] = $amountSign = isset(BANK_AMOUNT_SIGNS[$amountSign]) ? $amountSign : 'signed';
    $dateCol = $use['date'] ?? null;
    $firstIsHeader = $dateCol === null || parse_bank_date((string) bank_cell($header, $header, $dateCol), $dateFormat) === null;
    $data = $firstIsHeader ? array_slice($cells, 1) : $cells;
    $hdr = $firstIsHeader ? $header : [];
    $out['header'] = $firstIsHeader ? $header : [];
    foreach (['date', 'amount', 'debit', 'credit', 'description', 'reference', 'balance'] as $k) {
        $c = $use[$k] ?? null;
        $out['columns'][$k] = $c === null ? null : (is_int($c) || ctype_digit((string) $c) ? ($hdr[(int) $c] ?? 'column ' . ((int) $c + 1)) : (string) $c);
    }
    if ($dateCol === null) {
        $out['errors'][] = 'No date column was found: name it in the column map.';
    }
    if ($amountSign === 'signed' && ($use['amount'] ?? null) === null) {
        $out['errors'][] = 'No amount column was found: name it in the column map, or choose a debit and a credit column.';
    }
    if ($amountSign === 'debit_credit' && (($use['debit'] ?? null) === null && ($use['credit'] ?? null) === null)) {
        $out['errors'][] = 'No debit or credit column was found: name them in the column map.';
    }
    if ($out['errors'] !== []) {
        return $out;
    }
    $seen = [];
    $rowNo = $firstIsHeader ? 1 : 0;
    foreach ($data as $cellsRow) {
        $rowNo++;
        $out['total_rows']++;
        $dateRaw = (string) bank_cell($cellsRow, $hdr, $dateCol);
        $date = parse_bank_date($dateRaw, $dateFormat);
        if ($date === null) {
            $out['errors'][] = 'Row ' . $rowNo . ': the date "' . $dateRaw . '" is not a date in the order ' . $dateFormat . '.';
            continue;
        }
        if ($amountSign === 'signed') {
            $raw = (string) bank_cell($cellsRow, $hdr, $use['amount']);
            $amount = parse_bank_amount($raw);
            if ($amount === null) {
                $out['errors'][] = 'Row ' . $rowNo . ': the amount "' . $raw . '" is not an amount.';
                continue;
            }
        } else {
            $d = bank_cell($cellsRow, $hdr, $use['debit'] ?? null);
            $c = bank_cell($cellsRow, $hdr, $use['credit'] ?? null);
            $dv = $d === null || trim($d) === '' ? '0.00' : parse_bank_amount($d);
            $cv = $c === null || trim($c) === '' ? '0.00' : parse_bank_amount($c);
            if ($dv === null || $cv === null) {
                $out['errors'][] = 'Row ' . $rowNo . ': the debit "' . (string) $d . '" or credit "' . (string) $c . '" is not an amount.';
                continue;
            }
            $dv = ltrim($dv, '-');
            $cv = ltrim($cv, '-');
            $amount = m_sub($cv, $dv);
        }
        if (m_cmp($amount, '0') === 0) {
            $out['errors'][] = 'Row ' . $rowNo . ': the amount is zero.';
            continue;
        }
        $desc = trim((string) bank_cell($cellsRow, $hdr, $use['description'] ?? null));
        $ref = trim((string) bank_cell($cellsRow, $hdr, $use['reference'] ?? null));
        $balRaw = bank_cell($cellsRow, $hdr, $use['balance'] ?? null);
        $balance = $balRaw === null || trim($balRaw) === '' ? null : parse_bank_amount($balRaw);
        $hashed = false;
        if ($ref === '') {
            $key = $date . '|' . $amount . '|' . $desc;
            $seen[$key] = ($seen[$key] ?? 0) + 1;                          // two equal fees on one day stay two lines: the running index per file
            $ext = 'h:' . substr(hash('sha256', $key . '|' . $seen[$key]), 0, 32);
            $hashed = true;
            $out['hashed']++;
        } else {
            $ext = mb_substr($ref, 0, 120);
        }
        $out['rows'][] = ['txn_date' => $date, 'amount' => $amount, 'description' => $desc === '' ? '(no description)' : mb_substr($desc, 0, 300), 'reference' => $ref === '' ? null : mb_substr($ref, 0, 120), 'external_ref' => $ext, 'balance_after' => $balance, 'hashed' => $hashed];
    }
    return $out;
}

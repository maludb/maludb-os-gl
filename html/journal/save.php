<?php
declare(strict_types=1);
/**
 * Actions `journal_entry_draft` (no `entry`: the head + `lines` as JSON rows or the form's line_* arrays; log `journal_entry.draft`) and `journal_entry_update` (`entry`: a draft's date,
 * memo, dimensions; log `journal_entry.update` before/after): journal.write. The guard trigger refuses a non-draft in its words.
 */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
gl_handler_begin();
require_right('journal.write');
$pdo = db();
$me = (int) current_member_id();
$cur = load_entry($pdo, false);
$errors = [];
$head = [];
if ($cur === null || req_has('entry_date')) {
    $d = request_date('entry_date');
    if ($d === null || $d === false) {
        $errors['entry_date'] = 'The date is YYYY-MM-DD.';
    } else {
        $head['entry_date'] = $d;
    }
}
if (req_has('memo') || $cur === null) {
    $m = (string) req_val('memo');
    $head['memo'] = $m === '' ? null : mb_substr($m, 0, 300);
}
$deptField = req_has('department') ? 'department' : 'department_id';
if (req_has($deptField) || $cur === null) {
    $head['department_id'] = gl_ref($pdo, $deptField, $cur['department_id'] ?? null, 'SELECT 1 FROM departments WHERE id = :id AND archived_at IS NULL', 'department', $errors);
}
$siteField = req_has('site') ? 'site' : 'site_id';
if (req_has($siteField) || $cur === null) {
    $head['site_id'] = gl_ref($pdo, $siteField, $cur['site_id'] ?? null, 'SELECT 1 FROM sites WHERE id = :id AND archived_at IS NULL', 'site', $errors);
}
if ($cur === null) {
    $c = strtoupper((string) req_val('currency'));
    if ($c !== '' && !preg_match('/^[A-Z]{3}$/', $c)) {
        $errors['currency'] = 'The currency is three letters, or empty for the base.';
    } elseif ($c !== '') {
        $head['currency'] = $c;
    }
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$rows = $cur === null ? (request_line_rows() ?? []) : [];
try {
    $pdo->beginTransaction();
    if ($cur === null) {
        $lines = [];
        foreach ($rows as $i => $row) {
            $lines[] = normalize_line($pdo, is_array($row) ? $row : [], $i + 1);
        }
        $id = draft_entry($pdo, $head, $lines, $me);
        log_activity($pdo, 'journal_entry.draft', 'journal_entry', $id, ['journal_entry_id' => $id, 'after' => ['entry_date' => $head['entry_date'], 'source' => 'manual', 'lines' => count($lines), 'memo' => $head['memo']]]);
        $pdo->commit();
        gl_done('Drafted entry #' . $id . ' (' . count($lines) . ' line' . (count($lines) === 1 ? '' : 's') . ')', $id, gl_land(return_path('/journal/' . $id), 'saved'), 'journalEntryChanged', ['journal_entry_id' => $id]);
    }
    $id = $cur['journal_entry_id'];
    $d = update_entry($pdo, $id, $head, $me);
    if ($d['after'] !== []) {
        log_activity($pdo, 'journal_entry.update', 'journal_entry', $id, ['journal_entry_id' => $id, 'before' => $d['before'], 'after' => $d['after']]);
    }
    $pdo->commit();
} catch (Throwable $ex) {
    entry_refused($pdo, $ex);
}
gl_done($d['after'] === [] ? 'Nothing changed' : 'Saved the draft (' . implode(', ', array_keys($d['after'])) . ')', $id, gl_land(return_path('/journal/' . $id), 'saved'), 'journalEntryChanged', ['journal_entry_id' => $id, 'changed' => array_keys($d['after'])]);

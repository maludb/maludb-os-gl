<?php
declare(strict_types=1);
/**
 * Action `recurring_save` (`template` an id changes an existing one — also `recurring_template` / `template_id`; name, kind, cadence, every_n, next_date, end_date, active; the document as `template` JSON (an agent), or the kind's form fields: memo, department, site, currency, day_offset / vendor,
 * vendor_ref_pattern, terms_days / customer, reference_pattern and the line arrays line_account[], line_description[], line_debit[], line_credit[], line_quantity[], line_unit_price[]; log `recurring.save` before/after): settings.manage.
 * The shape is validated and every refusal names its line. A template's kind is fixed once made.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/recurring/handler.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$idRaw = req_has('recurring_template') ? req_val('recurring_template') : (req_has('template_id') ? req_val('template_id') : (req_has('template') && ctype_digit((string) req_val('template')) ? req_val('template') : null));
$id = $idRaw === null || $idRaw === '' ? null : (int) $idRaw;
$cur = null;
if ($id !== null) {
    require_visible($pdo, 'mcp_recurring_templates', 'recurring_template_id', $id, 'Template');
    $cur = template_state($pdo, $id) ?? refuse(404, 'Template not found.');
}
$errors = [];
$name = req_has('name') ? trim((string) req_val('name')) : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the template a name of up to 120 characters.';
}
$kind = $cur['kind'] ?? (string) req_val('kind');
if ($cur === null && !isset(TEMPLATE_KINDS[$kind])) {
    $errors['kind'] = 'The kind is journal, bill or invoice.';
} elseif ($cur !== null && req_has('kind') && (string) req_val('kind') !== $cur['kind']) {
    $errors['kind'] = 'A template\'s kind is fixed once made; make a new template.';
}
$cadence = req_has('cadence') ? (string) req_val('cadence') : (string) ($cur['cadence'] ?? 'monthly');
if (!isset(CADENCES[$cadence])) {
    $errors['cadence'] = 'The cadence is weekly, monthly, quarterly or yearly.';
}
$every = gl_int('every_n', $cur['every_n'] ?? 1, 1, 24, 'Every', $errors, false);
$next = request_date('next_date');
if ($next === false || ($next === null && $cur === null)) {
    $errors['next_date'] = 'The next date is YYYY-MM-DD.';
}
$next ??= $cur['next_date'] ?? null;
$end = req_has('end_date') ? request_date('end_date') : ($cur['end_date'] ?? null);
if ($end === false) {
    $errors['end_date'] = 'The end date is YYYY-MM-DD, or empty.';
    $end = null;
}
if ($end !== null && $next !== null && $end < $next) {
    $errors['end_date'] = 'The end date cannot be before the next date.';
}
$active = gl_yes('active', $cur['active'] ?? true);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
// the document: JSON (an agent), else the kind's form fields, else (an update) what the template already holds
$doc = null;
$raw = $_POST['template'] ?? null;
if ($raw !== null && !(is_string($raw) && ctype_digit(trim($raw)))) {
    $doc = is_array($raw) ? $raw : json_decode((string) $raw, true);
    if (!is_array($doc)) {
        gl_refuse_fields(['template' => 'template is the document to draft, as JSON.']);
    }
} elseif (isset($_POST['line_description']) && is_array($_POST['line_description'])) {
    $doc = [];
    foreach (['memo', 'department', 'site', 'currency', 'day_offset', 'vendor', 'vendor_ref_pattern', 'terms_days', 'customer', 'reference_pattern'] as $k) {
        if (req_has($k)) {
            $doc[$k] = (string) req_val($k);
        }
    }
    $doc = array_intersect_key($doc, array_flip(TEMPLATE_HEAD_KEYS[$kind]));
    $lines = [];
    foreach ($_POST['line_description'] as $i => $d) {
        $row = ['description' => $d];
        if ($kind === 'journal') {
            $row += ['account' => $_POST['line_account'][$i] ?? '', 'debit' => $_POST['line_debit'][$i] ?? '', 'credit' => $_POST['line_credit'][$i] ?? ''];
        } else {
            $row += [$kind === 'bill' ? 'expense_account' : 'income_account' => $_POST['line_account'][$i] ?? '', 'quantity' => $_POST['line_quantity'][$i] ?? '1', 'unit_price' => $_POST['line_unit_price'][$i] ?? ''];
        }
        if (trim((string) $d) === '' && trim((string) ($row['account'] ?? $row['expense_account'] ?? $row['income_account'] ?? '')) === '' && trim((string) ($row['debit'] ?? $row['unit_price'] ?? '')) === '' && trim((string) ($row['credit'] ?? '')) === '') {
            continue;
        }
        $lines[] = array_filter($row, static fn ($v) => $v !== '' && $v !== null);
    }
    $doc['lines'] = $lines;
} elseif ($cur !== null) {
    $doc = $cur['template'];
} else {
    gl_refuse_fields(['template' => 'A template needs its document: the lines it drafts.']);
}
try {
    $tpl = validate_template($pdo, $kind, $doc);
    $pdo->beginTransaction();
    $newId = save_template($pdo, $id, ['name' => $name, 'kind' => $kind, 'cadence' => $cadence, 'every_n' => $every, 'next_date' => $next, 'end_date' => $end, 'active' => $active], $tpl, $me);
    $after = template_log_state(template_state($pdo, $newId));
    if ($cur === null) {
        log_activity($pdo, 'recurring.save', 'recurring_template', $newId, ['after' => $after]);
    } else {
        $d = gl_diff(template_log_state($cur), $after);
        if ($d['after'] !== [] || ($cur['template'] ?? []) != $tpl) {
            log_activity($pdo, 'recurring.save', 'recurring_template', $newId, ['before' => $d['before'], 'after' => $d['after'] + (($cur['template'] ?? []) != $tpl ? ['document_changed' => true] : [])]);
        }
    }
    $pdo->commit();
} catch (Throwable $ex) {
    if ($ex instanceof DomainException && preg_match('/^Line (\d+)/', $ex->getMessage(), $mm)) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        gl_refuse_fields(['lines' => $ex->getMessage(), 'row' => (int) $mm[1]]);
    }
    ar_refused($pdo, $ex);
}
gl_done(($id === null ? 'Made the template ' : 'Saved the template ') . $name . ' (' . cadence_words($cadence, $every) . ', next ' . $next . ')', $newId, gl_land(return_path('/settings/recurring/' . $newId), 'saved'), 'recurringChanged', ['recurring_template_id' => $newId]);

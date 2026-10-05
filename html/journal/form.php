<?php
declare(strict_types=1);
/** /journal/new and /journal/{id}/edit — a manual entry's head and first lines, or a draft's head (screens `journal-entry-add`, `journal-entry-edit`; journal.write). Params on add: date, reverse_of. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/handler.php';
require_once dirname(__DIR__, 2) . '/app/features/journal/present.php';
require_once dirname(__DIR__, 2) . '/app/views/shared/account-select.php';
require_once dirname(__DIR__, 2) . '/app/views/shared/party-select.php';
require_right('journal.write');
require_human();
$pdo = db();
$cur = load_entry($pdo, false);
if ($cur !== null && $cur['status'] !== 'draft') {
    refuse(422, 'Entry ' . ($cur['number'] ?? '#' . $cur['journal_entry_id']) . ' is ' . $cur['status'] . ' and cannot be changed' . ($cur['status'] === 'posted' ? '; reverse it instead' : '') . '.');
}
$prefill = null;
$reverseOf = request_integer('reverse_of');
if ($cur === null && $reverseOf !== null) {
    try {
        $prefill = reversal_prefill($pdo, $reverseOf);
    } catch (DomainException $e) {
        refuse(422, $e->getMessage());
    }
}
$date = $cur['entry_date'] ?? $prefill['entry_date'] ?? (request_date('date') ?: date('Y-m-d'));
$screen = $cur === null ? 'journal-entry-add' : 'journal-entry-edit';
log_screen_view($pdo, $screen);
if (wants_json()) {
    respond_screen(['entry' => $cur === null ? null : present_entry($cur), 'prefill' => $prefill, 'date' => $date, 'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo)]);
}
render_screen($cur === null ? 'New entry' : 'Change draft ' . ($cur['number'] ?? '#' . $cur['journal_entry_id']), view('journal/form.php', ['cur' => $cur, 'prefill' => $prefill, 'date' => $date, 'reverseOf' => $reverseOf, 'pdo' => $pdo,
    'departments' => find_live_departments($pdo), 'sites' => find_live_sites($pdo), 'here' => here_url()]), ['activeNav' => 'journal', 'screen' => $screen, 'entity' => 'journalEntry', 'recordId' => $cur === null ? '' : (string) $cur['journal_entry_id']]);

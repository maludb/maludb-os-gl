<?php
declare(strict_types=1);
/** Action `morning_note_send` (body — what was done and what needs a person, plain text up to 4,000 characters; to_member, empty: the member the settings name; log `morning_note.send` with to_member_id and length): journal.write. The note is a notice of kind morning_note (the bell, the email and text the person chose); the home shows the newest. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/recurring/handler.php';
gl_handler_begin();
require_right('journal.write');
$pdo = db();
$errors = [];
$body = trim((string) req_val('body'));
if ($body === '') {
    $errors['body'] = 'Write the note: what was done and what needs a person.';
} elseif (mb_strlen($body) > 4000) {
    $errors['body'] = 'The note is up to 4,000 characters.';
}
$to = gl_ref($pdo, req_has('to_member') ? 'to_member' : 'to_member_id', null, "SELECT 1 FROM members m WHERE m.id = :id AND m.status = 'active' AND m.capability IS NOT NULL AND m.member_kind = 'human'", 'member', $errors);
if ($to === null && !isset($errors['to_member'])) {
    $to = ($m = $pdo->query('SELECT morning_note_member_id FROM gl_settings WHERE id = 1')->fetchColumn()) ? (int) $m : null;
    if ($to === null) {
        $errors['to_member'] = 'No one is named to receive the morning note: choose a member, or set one in the ledger settings.';
    }
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
try {
    $pdo->beginTransaction();
    $first = mb_substr(strtok($body, "\n") ?: $body, 0, 120);
    notify_member($pdo, $to, 'morning_note', 'Morning note: ' . $first, $body, null, null);
    log_activity($pdo, 'morning_note.send', 'notification', null, ['after' => ['to_member_id' => $to, 'length' => mb_strlen($body)]]);
    $pdo->commit();
} catch (Throwable $ex) {
    ar_refused($pdo, $ex);
}
gl_done('Sent the morning note (' . mb_strlen($body) . ' characters)', null, gl_land(return_path('/notifications'), 'saved'), 'notificationChanged', ['to_member_id' => $to, 'length' => mb_strlen($body)]);

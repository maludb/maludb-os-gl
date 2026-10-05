<?php
declare(strict_types=1);
/** Action `contact_save` (party_kind customer|vendor, party, contact to change one; log `contact.save`): ar.write for a customer, ap.write for a vendor. One primary: making one primary un-primaries the other. The files serve vendors too. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
$pdo = db();
$kind = (string) req_val('party_kind');
if (!in_array($kind, ['customer', 'vendor'], true)) {
    refuse(422, 'party_kind is customer or vendor.');
}
require_right($kind === 'customer' ? 'ar.write' : 'ap.write');
$party = req_id('party', 'party_id') ?? refuse(422, 'Say which ' . $kind . '.');
require_visible($pdo, $kind === 'customer' ? 'mcp_customers' : 'mcp_vendors', $kind === 'customer' ? 'customer_id' : 'vendor_id', $party, ucfirst($kind));
$contactId = req_id('contact', 'contact_id');
$errors = [];
$cur = null;
if ($contactId !== null) {
    foreach (find_contacts($pdo, $kind, $party) as $k) {
        if ($k['party_contact_id'] === $contactId) {
            $cur = $k;
        }
    }
    $cur ?? refuse(404, 'Contact not found.');
}
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the contact a name of up to 120 characters.';
}
$email = req_email('email', $errors, $cur['email'] ?? null);
$f = ['name' => $name, 'email' => $email, 'phone' => req_has('phone') ? ((string) req_val('phone') === '' ? null : mb_substr((string) req_val('phone'), 0, 60)) : ($cur['phone'] ?? null),
      'role' => req_has('role') ? ((string) req_val('role') === '' ? null : mb_substr((string) req_val('role'), 0, 80)) : ($cur['role'] ?? null), 'is_primary' => gl_yes('is_primary', (bool) ($cur['is_primary'] ?? false))];
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$id = gl_guard($pdo, static function () use ($pdo, $kind, $party, $contactId, $f): int {
    $pdo->beginTransaction();
    $id = save_contact($pdo, $kind, $party, $contactId, $f);
    log_activity($pdo, 'contact.save', $kind, $party, [$kind . '_id' => $party, 'after' => ['contact_id' => $id, 'name' => $f['name'], 'role' => $f['role'], 'is_primary' => $f['is_primary'], 'has_email' => $f['email'] !== null]]);
    $pdo->commit();
    return $id;
});
gl_done(($contactId === null ? 'Added the contact ' : 'Saved the contact ') . $f['name'], $id, gl_land(return_path(($kind === 'customer' ? '/customers/' : '/vendors/') . $party), 'saved', 'contact-' . $id), change_event($kind), ['contact_id' => $id]);

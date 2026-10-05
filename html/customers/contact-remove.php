<?php
declare(strict_types=1);
/** Action `contact_remove` (log `contact.remove`; confirm): ar.write for a customer's contact, ap.write for a vendor's. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/receivables/handler.php';
gl_handler_begin();
$pdo = db();
$id = req_id('contact', 'contact_id') ?? refuse(422, 'Say which contact.');
$st = $pdo->prepare('SELECT party_kind, party_id FROM party_contacts WHERE id = :id');
$st->execute(['id' => $id]);
$row = $st->fetch() ?: refuse(404, 'Contact not found.');
require_right($row['party_kind'] === 'customer' ? 'ar.write' : 'ap.write');
$gone = gl_guard($pdo, static function () use ($pdo, $id, $row): array {
    $pdo->beginTransaction();
    $g = remove_contact($pdo, $id) ?? throw new DomainException('Not found.');
    log_activity($pdo, 'contact.remove', $row['party_kind'], (int) $row['party_id'], [$row['party_kind'] . '_id' => (int) $row['party_id'], 'after' => ['contact_id' => $id, 'name' => $g['name']]]);
    $pdo->commit();
    return $g;
});
gl_done('Removed the contact ' . $gone['name'], $id, gl_land(return_path(($row['party_kind'] === 'customer' ? '/customers/' : '/vendors/') . (int) $row['party_id']), 'saved'), change_event($row['party_kind']));

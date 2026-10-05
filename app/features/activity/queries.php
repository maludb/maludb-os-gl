<?php
declare(strict_types=1);

/** The activity trail the caller may see (mcp_activity_log: their own rows always; every row with reports.view — the books are business-wide). */
const ACTIVITY_PAGE = 50;

/** Rows newest first, one page; `more` says whether another page exists. $filters: own (bool), action (prefix), since (days), entity_type + entity_id (a record's history). */
function find_my_activity(PDO $pdo, int $memberId, int $page, array $filters = []): array
{
    $where = [];
    $args = [];
    if (($filters['entity_type'] ?? '') !== '' && ($filters['entity_id'] ?? null) !== null) {
        $where[] = '((entity_type = :et AND entity_id = :eid) OR (:et = \'journal_entry\' AND journal_entry_id = :eid) OR (:et = \'customer\' AND customer_id = :eid) OR (:et = \'vendor\' AND vendor_id = :eid) OR (:et = \'bank_account\' AND bank_account_id = :eid) OR (:et = \'fiscal_period\' AND period_id = :eid))';
        $args['et'] = (string) $filters['entity_type'];
        $args['eid'] = (int) $filters['entity_id'];
    } elseif ($filters['own'] ?? true) {
        $where[] = 'actor_member_id = :member';
        $args['member'] = $memberId;
    }
    if (($filters['action'] ?? '') !== '') {
        $where[] = 'action LIKE :action';
        $args['action'] = str_replace(['%', '_'], ['\\%', '\\_'], rtrim($filters['action'], '.')) . '%';
    }
    if (($filters['since'] ?? null) !== null) {
        $where[] = 'occurred_at > now() - make_interval(days => :since)';
        $args['since'] = (int) $filters['since'];
    }
    $sql = 'SELECT activity_id, occurred_at, actor_member_id, actor_name, actor_kind, actor_customer_id, source, action, entity_type, entity_id, journal_entry_id, period_id, customer_id, vendor_id, bank_account_id, after, agent_run_id
              FROM mcp_activity_log' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
        . ' ORDER BY occurred_at DESC, activity_id DESC LIMIT ' . (ACTIVITY_PAGE + 1) . ' OFFSET ' . (($page - 1) * ACTIVITY_PAGE);
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll();
    return ['rows' => array_slice($rows, 0, ACTIVITY_PAGE), 'more' => count($rows) > ACTIVITY_PAGE];
}

/** A record's trail (the timeline every record page shows), newest first. */
function find_record_activity(PDO $pdo, string $type, int $id, int $limit = 100): array
{
    return array_slice(find_my_activity($pdo, 0, 1, ['entity_type' => $type, 'entity_id' => $id])['rows'], 0, $limit);
}

/** The words for each event (the manifest's log events). Later slices add theirs. */
const ACTIVITY_WORDS = [
    'member.sign_on' => 'signed on', 'member.sign_out' => 'signed out', 'directory.sync' => 'refreshed the directory', 'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token',
    'assistant.ask' => 'asked the expert', 'prefs.save' => 'changed how they are told', 'notification.read' => 'read their notifications', 'settings.save' => 'changed the ledger\'s settings', 'numbering.save' => 'changed the numbering',
    'account.save' => 'changed an account', 'account.archive' => 'archived an account', 'fiscal_year.create' => 'opened a fiscal year', 'period.close' => 'closed a period', 'period.reopen' => 'reopened a period',
    'fiscal_year.close' => 'closed the year', 'fiscal_year.reopen' => 'reopened the year', 'tax_rate.save' => 'changed a tax rate', 'tax_rate.archive' => 'archived a tax rate', 'item.save' => 'changed an item', 'item.archive' => 'archived an item',
    'posting_rule.save' => 'changed a posting rule', 'site.save' => 'changed a site', 'site.archive' => 'archived a site',
    'journal_entry.draft' => 'drafted an entry', 'journal_entry.update' => 'changed a draft entry', 'journal_entry.line_save' => 'changed a line', 'journal_entry.line_remove' => 'removed a line', 'journal_entry.post' => 'posted an entry',
    'journal_entry.post_batch' => 'posted several entries', 'journal_entry.reverse' => 'reversed an entry', 'journal_entry.void' => 'voided a draft entry', 'attachment.add' => 'attached a document', 'attachment.delete' => 'removed an attachment', 'note.add' => 'added a note',
    'customer.save' => 'changed a customer', 'customer.archive' => 'archived a customer', 'customer.delete' => 'deleted a customer', 'contact.save' => 'changed a contact', 'contact.remove' => 'removed a contact',
    'invoice.draft' => 'drafted an invoice', 'invoice.update' => 'changed a draft invoice', 'invoice.line_save' => 'changed an invoice line', 'invoice.line_remove' => 'removed an invoice line', 'invoice.issue' => 'issued an invoice', 'invoice.send' => 'sent an invoice',
    'invoice.remind' => 'sent a reminder', 'invoice.remind_due' => 'a reminder went out', 'invoice.view' => 'opened the invoice link', 'invoice.void' => 'voided an invoice', 'invoice.write_off' => 'wrote off an invoice', 'invoice.delete' => 'deleted a draft invoice',
    'invoice.secure_link_rotate' => 'rotated an invoice link', 'invoice.link_expired' => 'an invoice link expired', 'statement.send' => 'sent a statement', 'credit_note.draft' => 'drafted a credit note', 'credit_note.update' => 'changed a draft credit note', 'credit_note.line_save' => 'changed a credit note line',
    'credit_note.line_remove' => 'removed a credit note line', 'credit_note.issue' => 'issued a credit note', 'credit_note.apply' => 'applied a credit note', 'credit_note.unapply' => 'unapplied a credit note', 'credit_note.void' => 'voided a credit note', 'credit_note.delete' => 'deleted a draft credit note',
    'receipt.record' => 'recorded a receipt', 'receipt.apply' => 'applied a receipt', 'receipt.unapply' => 'unapplied a receipt', 'receipt.void' => 'voided a receipt', 'claim.submit' => 'said they paid', 'claim.confirm' => 'confirmed a payment claim', 'claim.dismiss' => 'dismissed a payment claim',
    'deposit.draft' => 'started a deposit', 'deposit.add_receipt' => 'added a receipt to a deposit', 'deposit.remove_receipt' => 'took a receipt out of a deposit', 'deposit.record' => 'recorded a deposit', 'deposit.void' => 'voided a deposit', 'deposit.delete' => 'deleted a draft deposit',
    'vendor.save' => 'changed a vendor', 'vendor.archive' => 'archived a vendor', 'vendor.delete' => 'deleted a vendor', 'bill.draft' => 'entered a bill', 'bill.update' => 'changed a draft bill', 'bill.line_save' => 'changed a bill line', 'bill.line_remove' => 'removed a bill line',
    'bill.approve' => 'approved a bill', 'bill.void' => 'voided a bill', 'bill.delete' => 'deleted a draft bill', 'vendor_credit.draft' => 'entered a vendor credit', 'vendor_credit.update' => 'changed a vendor credit', 'vendor_credit.line_save' => 'changed a vendor credit line',
    'vendor_credit.line_remove' => 'removed a vendor credit line', 'vendor_credit.issue' => 'issued a vendor credit', 'vendor_credit.apply' => 'applied a vendor credit', 'vendor_credit.unapply' => 'unapplied a vendor credit', 'vendor_credit.void' => 'voided a vendor credit', 'vendor_credit.delete' => 'deleted a draft vendor credit',
    'payment.draft' => 'prepared a payment', 'payment.update' => 'changed a draft payment', 'payment.apply' => 'applied a payment to a bill', 'payment.unapply' => 'unapplied a payment', 'payment.approve' => 'approved a payment', 'payment.record' => 'recorded a payment as made', 'payment.void' => 'voided a payment', 'payment.delete' => 'deleted a draft payment',
    'payment_run.create' => 'made a payment run', 'payment_run.add' => 'added a payment to a run', 'payment_run.remove' => 'took a payment out of a run', 'payment_run.approve' => 'approved a payment run', 'payment_run.record' => 'recorded a payment run as paid', 'payment_run.void' => 'voided a payment run',
    'bank_account.save' => 'changed a bank account', 'bank_account.archive' => 'archived a bank account', 'bank.import' => 'imported bank lines', 'bank.import_delete' => 'deleted a bank import', 'bank.line_add' => 'typed a bank line', 'bank.match' => 'matched a bank line', 'bank.unmatch' => 'unmatched a bank line',
    'bank.ignore' => 'ignored a bank line', 'bank.entry_from_line' => 'made an entry from a bank line', 'reconciliation.start' => 'started a reconciliation', 'reconciliation.complete' => 'completed a reconciliation', 'reconciliation.reopen' => 'reopened a reconciliation', 'reconciliation.abandon' => 'abandoned a reconciliation',
    'transfer.record' => 'recorded a transfer', 'transfer.void' => 'voided a transfer', 'ai_statement.pull' => 'pulled the OS\'s AI statement', 'ai_statement.import' => 'imported an AI statement into bills', 'ai_statement.check' => 'checked an AI statement against its bills',
    'recurring.save' => 'changed a recurring template', 'recurring.toggle' => 'turned a recurring template on or off', 'recurring.run' => 'ran a recurring template', 'recurring.delete' => 'deleted a recurring template', 'morning_note.send' => 'sent the morning note',
    'notification.send' => 'sent a notice', 'agent.dispatch' => 'handed something to an agent', 'worker.pass' => 'the worker ran', 'export.download' => 'downloaded an export',
];

/** One line for a row, in words: "Bea Lee posted JE-000124 (1,608.24 USD)". Falls back to the event name. */
function activity_sentence(array $r): string
{
    $who = $r['actor_name'] ?? ($r['actor_customer_id'] !== null ? 'A customer' : 'The ledger');
    if (($r['actor_kind'] ?? '') === 'agent') {
        $who .= ' (agent)';
    }
    if ($r['action'] === 'screen.view') {
        return $who . ' opened a screen';
    }
    $after = is_string($r['after'] ?? null) ? (json_decode($r['after'], true) ?: []) : (array) ($r['after'] ?? []);
    $what = ACTIVITY_WORDS[$r['action']] ?? str_replace(['.', '_'], [' ', ' '], (string) $r['action']);
    $tail = '';
    if (isset($after['number'])) {
        $tail .= ' ' . $after['number'];
    } elseif (isset($after['reversal_number'])) {
        $tail .= ' with ' . $after['reversal_number'];
    }
    if (isset($after['amount'], $after['currency'])) {
        $tail .= ' (' . number_format(abs((float) $after['amount']), 2) . ' ' . $after['currency'] . ')';
    }
    if (isset($after['reason']) && is_string($after['reason']) && $after['reason'] !== '') {
        $tail .= ': ' . mb_substr($after['reason'], 0, 80);
    }
    return $who . ' ' . $what . $tail;
}

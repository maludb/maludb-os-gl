<?php
declare(strict_types=1);

/** The JSON shapes of a customer — whitelists; an address or a note only when the view gave it (ar.write). */
function present_customer(array $c): array
{
    return ['customer_id' => $c['customer_id'], 'name' => $c['name'], 'legal_name' => $c['legal_name'], 'email' => $c['email'], 'phone' => $c['phone'], 'billing_address' => $c['billing_address'], 'shipping_address' => $c['shipping_address'],
            'tax_id' => $c['tax_id'], 'terms_days' => $c['terms_days'], 'currency' => $c['currency'], 'income_account_id' => $c['income_account_id'], 'tax_rate_id' => $c['tax_rate_id'], 'member_id' => $c['member_id'], 'notes' => $c['notes'],
            'archived' => $c['archived_at'] !== null, 'balance_due' => $c['balances'] ?? [], 'open_invoices' => $c['open_invoices'], 'last_invoiced_on' => $c['last_invoiced_on'],
            'contacts' => array_map(static fn (array $k): array => ['contact_id' => $k['party_contact_id'], 'name' => $k['name'], 'email' => $k['email'], 'phone' => $k['phone'], 'role' => $k['role'], 'is_primary' => (bool) $k['is_primary']], $c['contacts'] ?? [])];
}

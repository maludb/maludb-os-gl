<?php
declare(strict_types=1);

/** The JSON shapes of a vendor — whitelists; the address, tax id and notes only when the view gave them (ap.write). */
function present_vendor(array $v): array
{
    return ['vendor_id' => $v['vendor_id'], 'name' => $v['name'], 'legal_name' => $v['legal_name'], 'kind' => $v['kind'], 'provider_key' => $v['provider_key'], 'email' => $v['email'], 'phone' => $v['phone'], 'remit_address' => $v['remit_address'],
            'tax_id' => $v['tax_id'], 'terms_days' => $v['terms_days'], 'currency' => $v['currency'], 'expense_account_id' => $v['expense_account_id'], 'pay_method' => $v['pay_method'], 'member_id' => $v['member_id'], 'notes' => $v['notes'],
            'archived' => $v['archived_at'] !== null, 'balance_due' => $v['balances'] ?? [], 'open_bills' => $v['open_bills'], 'last_billed_on' => $v['last_billed_on'],
            'contacts' => array_map(static fn (array $k): array => ['contact_id' => $k['party_contact_id'], 'name' => $k['name'], 'email' => $k['email'], 'phone' => $k['phone'], 'role' => $k['role'], 'is_primary' => (bool) $k['is_primary']], $v['contacts'] ?? [])];
}

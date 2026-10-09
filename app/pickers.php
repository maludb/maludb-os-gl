<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; docs/20-record-picker.md the record). One entry per record kind.
 * `search` reuses the feature's own query (the same view and the same ILIKE as its list screen, paged — a list card's balances are left out),
 * `label` names one record, `rights` gates the endpoint with the right the source's view already demands (any one of them admits), `params` are
 * the only filter keys the endpoint forwards (integers). The GL's gate is a right (gl_has_right), not a role, so `rights` stands where the
 * reference says `roles`. Rows: id, label, detail (one muted line), badge + color — optional.
 *
 *   account   the chart, "code — name". Params `types` (the sum of ACCOUNT_PICK_TYPE_BITS: asset 1, liability 2, equity 4, income 8, expense 16;
 *             none = every type) and `flags` (is_bank yes 1 / no 2, is_control yes 4 / no 8) are accounts_for_select()'s $types and $flags as two integers.
 *   vendor, customer, item   the parties and the catalog.   member   a person or an agent of the business (customers' and vendors' linked member, the morning note's).
 */
return [
    'account' => [
        'title' => 'Accounts', 'noun' => 'account', 'rights' => ['reports.view'], 'params' => ['types', 'flags'],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/accounts/queries.php';
            return accounts_pick_page($pdo, $q, $params['types'] ?? null, $params['flags'] ?? null, $page);
        },
        'label' => static function (PDO $pdo, int $id): ?string {
            require_once __DIR__ . '/features/accounts/queries.php';
            return account_pick_label($pdo, $id);
        },
    ],
    'vendor' => [
        'title' => 'Vendors', 'noun' => 'vendor', 'rights' => ['parties.view'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/vendors/queries.php';
            return vendors_pick_page($pdo, $q, $page);
        },
        'label' => static function (PDO $pdo, int $id): ?string {
            require_once __DIR__ . '/features/vendors/queries.php';
            return vendor_pick_label($pdo, $id);
        },
    ],
    'customer' => [
        'title' => 'Customers', 'noun' => 'customer', 'rights' => ['parties.view'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/customers/queries.php';
            return customers_pick_page($pdo, $q, $page);
        },
        'label' => static function (PDO $pdo, int $id): ?string {
            require_once __DIR__ . '/features/customers/queries.php';
            return customer_pick_label($pdo, $id);
        },
    ],
    'item' => [
        'title' => 'Items', 'noun' => 'item', 'rights' => ['documents.view'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/settings/ledger.php';
            return items_pick_page($pdo, $q, $page);
        },
        'label' => static function (PDO $pdo, int $id): ?string {
            require_once __DIR__ . '/features/settings/ledger.php';
            return item_pick_label($pdo, $id);
        },
    ],
    'member' => [
        'title' => 'People and agents', 'noun' => 'person', 'rights' => ['ar.write', 'ap.write', 'settings.manage'], 'params' => [],
        'search' => static function (PDO $pdo, string $q, array $params, int $page): array {
            require_once __DIR__ . '/features/settings/ledger.php';
            return members_pick_page($pdo, $q, $page);
        },
        'label' => static function (PDO $pdo, int $id): ?string {
            require_once __DIR__ . '/features/settings/ledger.php';
            return member_pick_label($pdo, $id);
        },
    ],
];

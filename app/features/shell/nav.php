<?php
declare(strict_types=1);

/**
 * The shell's menu — ONE table: the sidebar and the phone's tab bar read it (Phase 2 builds the shell), so a menu item and
 * the right that opens its screen can never disagree. An item shows only when the person holds its right (several joined
 * by "|": any one). Screens are the action manifest's (Phase 1); until a screen is built its item points at a page the
 * shell marks as coming.
 *   [id, url, icon, label, right]
 */
function nav_groups(): array
{
    return [
        'Books' => [
            ['home',        '/',                  'feather-home',         'Home',               'reports.view'],
            ['statements',  '/statements/',       'feather-file-text',    'Statements',         'reports.view'],
            ['journal',     '/journal/',          'feather-book',         'Journal',            'documents.view'],
            ['accounts',    '/accounts/',         'feather-list',         'Chart of accounts',  'reports.view'],
            ['periods',     '/periods/',          'feather-calendar',     'Periods',            'reports.view'],
        ],
        'Receivables' => [
            ['customers',   '/customers/',        'feather-users',        'Customers',          'parties.view'],
            ['invoices',    '/invoices/',         'feather-file-plus',    'Invoices',           'documents.view'],
            ['receipts',    '/receipts/',         'feather-download',     'Receipts',           'documents.view'],
            ['deposits',    '/deposits/',         'feather-archive',      'Deposits',           'documents.view'],
            ['credit-notes','/credit-notes/',     'feather-corner-up-left','Credit notes',      'documents.view'],
            ['ar-aging',    '/reports/ar-aging',  'feather-clock',        'Who owes us',        'reports.view'],
        ],
        'Payables' => [
            ['vendors',     '/vendors/',          'feather-truck',        'Vendors',            'parties.view'],
            ['bills',       '/bills/',            'feather-file-minus',   'Bills',              'documents.view'],
            ['payments',    '/payments/',         'feather-upload',       'Payments',           'documents.view'],
            ['payment-runs','/payment-runs/',     'feather-layers',       'Payment runs',       'documents.view'],
            ['vendor-credits','/vendor-credits/', 'feather-corner-up-right','Vendor credits',   'documents.view'],
            ['ai',          '/ap/ai/',            'feather-cpu',          'AI statements',      'reports.view'],
            ['ap-aging',    '/reports/ap-aging',  'feather-clock',        'Whom we owe',        'reports.view'],
        ],
        'Cash' => [
            ['banks',       '/cash/',             'feather-credit-card',  'Bank accounts',      'documents.view'],
            ['match',       '/cash/match',        'feather-check-square', 'Match the bank',     'bank.write'],
            ['reconcile',   '/cash/reconcile',    'feather-check-circle', 'Reconcile',          'bank.write'],
            ['transfers',   '/cash/transfers/',   'feather-repeat',       'Transfers',          'documents.view'],
        ],
        'Settings' => [
            ['general',     '/settings/general',  'feather-sliders',      'Ledger settings',    'settings.manage'],
            ['tax-rates',   '/settings/tax-rates/','feather-percent',     'Tax rates',          'settings.manage'],
            ['items',       '/settings/items/',   'feather-package',      'Items',              'settings.manage'],
            ['posting-rules','/settings/posting-rules/','feather-git-merge','Posting rules',    'settings.manage'],
            ['bank-settings','/settings/banks/',  'feather-credit-card',  'Bank accounts',      'settings.manage'],
            ['recurring',   '/settings/recurring/','feather-refresh-cw',  'Recurring',          'settings.manage'],
            ['sites',       '/settings/sites/',   'feather-map-pin',      'Sites',              'settings.manage'],
            ['audit',       '/audit',             'feather-shield',       'Audit',              'periods.manage|journal.override'],
            ['exports',     '/exports',           'feather-download-cloud','Exports',           'exports.download'],
            ['agents',      '/settings/agents',   'feather-cpu',          'Agents',             'settings.manage'],
            ['my-settings', '/settings/',         'feather-settings',     'My settings',        'reports.view'],
        ],
    ];
}

/** The phone's tabs: Home, Statements, Invoices, Bills — and More (the sidebar) which the layout adds. */
function nav_tabs(): array
{
    return [
        ['home',       '/',             'feather-home',       'Home',       'reports.view'],
        ['statements', '/statements/',  'feather-file-text',  'Statements', 'reports.view'],
        ['invoices',   '/invoices/',    'feather-file-plus',  'Invoices',   'documents.view'],
        ['bills',      '/bills/',       'feather-file-minus', 'Bills',      'documents.view'],
    ];
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/** The highest ledger role the member holds, in words — the header's badge. */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin') {
        return 'Super-admin';
    }
    $st = db()->prepare('SELECT roles FROM members WHERE id = :id');
    $st->execute(['id' => (int) $m['id']]);
    $held = pg_text_array((string) ($st->fetchColumn() ?: '{}'));
    if ($held === []) {
        return ($m['capability'] ?? '') === 'admin' ? 'GL admin' : 'Viewer';
    }
    foreach (['admin' => 'GL admin', 'controller' => 'Controller', 'accountant' => 'Accountant', 'bookkeeper' => 'Bookkeeper', 'user' => 'Viewer'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    return 'Viewer';
}

/** A PostgreSQL text[] literal ({a,b}) as a PHP list; quoted elements are unquoted. */
function pg_text_array(string $literal): array
{
    $literal = trim($literal);
    if ($literal === '' || $literal === '{}') {
        return [];
    }
    $out = [];
    foreach (str_getcsv(substr($literal, 1, -1), ',', '"', '\\') as $v) {
        if ($v !== null && $v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** A path a page may send a person back to: local, no scheme, no protocol-relative — else null. */
function safe_local_path(?string $path): ?string
{
    if ($path === null || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1f]/', $path)) {
        return null;
    }
    return $path;
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. Later slices add their screens here. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    $labels = ['/' => 'Home', '/notifications' => 'Notifications', '/activity' => 'Activity', '/settings/' => 'My settings', '/settings/tokens/' => 'Tokens',
               '/statements/pl' => 'Profit and loss', '/statements/balance-sheet' => 'Balance sheet', '/statements/trial-balance' => 'Trial balance',
               '/statements/cash-flow' => 'Cash flow', '/reports/ar-aging' => 'Who owes us', '/reports/ap-aging' => 'Whom we owe', '/cash/match' => 'Match the bank',
               '/cash/reconcile' => 'Reconcile', '/cash/import' => 'Import a statement', '/audit' => 'Audit', '/exports' => 'Exports'];
    foreach ($labels as $p => $label) {
        if ($path === $p) {
            return [$back, $label];
        }
    }
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[1] === $path) {
                return [$back, $i[3]];
            }
        }
    }
    foreach (['journal' => 'the entry', 'invoices' => 'the invoice', 'bills' => 'the bill', 'customers' => 'the customer', 'vendors' => 'the vendor',
              'receipts' => 'the receipt', 'payments' => 'the payment', 'deposits' => 'the deposit', 'payment-runs' => 'the payment run',
              'credit-notes' => 'the credit note', 'vendor-credits' => 'the vendor credit', 'accounts' => 'the account', 'cash/banks' => 'the bank account',
              'cash/transfers' => 'the transfer', 'ap/ai' => 'the AI statement', 'periods' => 'the period'] as $dir => $label) {
        if (preg_match('#^/' . $dir . '/\d+$#', $path)) {
            return [$back, $label];
        }
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here);
}

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which
 * slice builds it — 200 after the right has been checked. bin/build_action_registry.php reads a controller that calls this
 * as unbuilt, so the voice surface never offers the screen. Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId, string $slice, string $right): void
{
    $item = nav_item($navId);
    $title = $item[3] ?? ucfirst(str_replace('-', ' ', $navId));
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), $navId);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    $html = view('shared/header.php', ['id' => $navId, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]])
        . '<div class="main-content" id="' . e($navId) . '-content"><div class="card coming-card" id="' . e($navId) . '-coming"><div class="card-body">'
        . '<div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="' . e($item[2] ?? 'feather-box') . '"></i></span>'
        . '<div><div class="fw-semibold">' . e($title) . ' is not built yet</div><div class="fs-12 text-muted">' . e($slice) . ' builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>'
        . '</div></div></div>';
    render_screen($title, $html, ['activeNav' => $navId, 'screen' => $navId]);
}

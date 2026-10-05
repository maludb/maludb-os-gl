<?php
declare(strict_types=1);
/**
 * The ONE account picker (slice 1; every later slice uses it): account_select($pdo, $name, $selected, $types = [], $flags = [], $attrs = '', $empty = 'Choose an account…')
 * — a <select> of the live accounts of the given types (and flags: is_bank, is_control), "code — name", over mcp_accounts.
 */
function account_select(PDO $pdo, string $name, ?int $selected, array $types = [], array $flags = [], string $attrs = '', ?string $empty = 'Choose an account…', string $id = ''): string
{
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    $h = '<select name="' . e($name) . '" id="' . e($id) . '" class="form-select btn-touch"' . ($attrs !== '' ? ' ' . $attrs : '') . '>';
    if ($empty !== null) {
        $h .= '<option value="">' . e($empty) . '</option>';
    }
    foreach (accounts_for_select($pdo, $types, $flags) as $a) {
        $h .= '<option value="' . $a['account_id'] . '"' . ($selected === $a['account_id'] ? ' selected' : '') . '>' . e($a['code'] . ' — ' . $a['name']) . '</option>';
    }
    return $h . '</select>';
}

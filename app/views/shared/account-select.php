<?php
declare(strict_types=1);
/**
 * The ONE account field (slice 1; every later slice uses it; the record picker since 2026-10-09, docs/20-record-picker.md):
 * account_select($pdo, $name, $selected, $types = [], $flags = [], $attrs = '', $empty = 'Choose an account…', $id = '')
 * — a picker over the live accounts of the given types (and flags: is_bank, is_control), "code — name", over mcp_accounts. The hidden input keeps $name and
 * $id, so a handler, an hx-include and a form= attribute read it as they read the select. $empty is the placeholder and the "no account" the Clear button
 * returns to; null means a choice is required (no Clear). $attrs carries form="…" and required, as before. The filter travels as two integers (app/pickers.php).
 */
function account_select(PDO $pdo, string $name, ?int $selected, array $types = [], array $flags = [], string $attrs = '', ?string $empty = 'Choose an account…', string $id = ''): string
{
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    [$required, $extra] = picker_attrs($attrs);
    $masks = account_pick_masks($types, $flags);
    return picker_field([
        'id' => $id, 'name' => $name, 'source' => 'account', 'value' => $selected, 'placeholder' => $empty ?? 'Choose an account…',
        'required' => $required || $empty === null, 'extra' => $extra, 'params' => array_filter($masks), 'search' => 'Search accounts by code or name…',
    ]);
}

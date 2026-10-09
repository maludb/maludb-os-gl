<?php
declare(strict_types=1);
/** The ONE item field (the record picker, 2026-10-09): item_select($pdo, $name, $selected, $attrs, $empty, $id) — a picker over the live items (mcp_items, name order, searched by name or code), the hidden input under $name and $id. $attrs: form="…", required. */
function item_select(PDO $pdo, string $name, ?int $selected, string $attrs = '', ?string $empty = '— none —', string $id = ''): string
{
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    [$required, $extra] = picker_attrs($attrs);
    return picker_field(['id' => $id, 'name' => $name, 'source' => 'item', 'value' => $selected, 'placeholder' => $empty ?? 'Choose an item', 'required' => $required || $empty === null, 'extra' => $extra, 'search' => 'Search items by name or code…']);
}

<?php
declare(strict_types=1);
/** The ONE customer field (slice 4; the record picker since 2026-10-09): customer_select($pdo, $name, $selected, $attrs, $empty, $id) — a picker over the live customers (mcp_customers, name order, searched by name), the hidden input under $name and $id. $attrs: form="…", required. */
function customer_select(PDO $pdo, string $name, ?int $selected, string $attrs = '', ?string $empty = 'Choose a customer…', string $id = ''): string
{
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    [$required, $extra] = picker_attrs($attrs);
    return picker_field(['id' => $id, 'name' => $name, 'source' => 'customer', 'value' => $selected, 'placeholder' => $empty ?? 'Choose a customer…', 'required' => $required || $empty === null, 'extra' => $extra]);
}

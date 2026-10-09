<?php
declare(strict_types=1);
/** The ONE vendor field (slice 5; the record picker since 2026-10-09): vendor_select($pdo, $name, $selected, $attrs, $empty, $id) — a picker over the live vendors (mcp_vendors, name order, searched by name), the hidden input under $name and $id. $attrs: form="…", required. */
function vendor_select(PDO $pdo, string $name, ?int $selected, string $attrs = '', ?string $empty = 'Choose a vendor…', string $id = ''): string
{
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    [$required, $extra] = picker_attrs($attrs);
    return picker_field(['id' => $id, 'name' => $name, 'source' => 'vendor', 'value' => $selected, 'placeholder' => $empty ?? 'Choose a vendor…', 'required' => $required || $empty === null, 'extra' => $extra]);
}

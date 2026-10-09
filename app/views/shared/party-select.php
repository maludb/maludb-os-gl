<?php
declare(strict_types=1);
/** The ONE party field (slice 2; the record picker since 2026-10-09): party_select($pdo, 'customer'|'vendor', $name, $selected, $attrs, $empty, $id) — the customer or vendor picker; $attrs: form="…", required (a class styled the select and is dropped). */
function party_select(PDO $pdo, string $kind, string $name, ?int $selected, string $attrs = '', ?string $empty = 'None', string $id = ''): string
{
    $kind = $kind === 'vendor' ? 'vendor' : 'customer';
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    [$required, $extra] = picker_attrs($attrs);
    return picker_field(['id' => $id, 'name' => $name, 'source' => $kind, 'value' => $selected, 'placeholder' => $empty ?? 'Choose a ' . $kind, 'required' => $required || $empty === null, 'extra' => $extra]);
}

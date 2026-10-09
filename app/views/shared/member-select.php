<?php
declare(strict_types=1);
/** The ONE member field (the record picker, 2026-10-09): member_select($pdo, $name, $selected, $attrs, $empty, $id) — a picker over the people and agents a settings field may name (mcp_members: active, admitted), the hidden input under $name and $id. */
function member_select(PDO $pdo, string $name, ?int $selected, string $attrs = '', ?string $empty = 'None', string $id = ''): string
{
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    [$required, $extra] = picker_attrs($attrs);
    return picker_field(['id' => $id, 'name' => $name, 'source' => 'member', 'value' => $selected, 'placeholder' => $empty ?? 'Choose a person', 'required' => $required || $empty === null, 'extra' => $extra, 'search' => 'Search people and agents…']);
}

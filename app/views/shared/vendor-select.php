<?php
declare(strict_types=1);
/** The ONE vendor picker (slice 5): vendor_select($pdo, $name, $selected, $attrs, $empty, $id) — live vendors over mcp_vendors, name order, searchable by typing (a native select). */
function vendor_select(PDO $pdo, string $name, ?int $selected, string $attrs = '', ?string $empty = 'Choose a vendor…', string $id = ''): string
{
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    $h = '<select name="' . e($name) . '" id="' . e($id) . '" class="form-select btn-touch"' . ($attrs !== '' ? ' ' . $attrs : '') . '>';
    if ($empty !== null) {
        $h .= '<option value="">' . e($empty) . '</option>';
    }
    foreach (vendors_for_select($pdo) as $v) {
        $h .= '<option value="' . $v['vendor_id'] . '"' . ($selected === $v['vendor_id'] ? ' selected' : '') . '>' . e($v['name']) . '</option>';
    }
    return $h . '</select>';
}

<?php
declare(strict_types=1);
/** The ONE customer picker (slice 4; slice 5 adds vendor-select.php beside it): customer_select($pdo, $name, $selected, $attrs, $empty, $id) — live customers over mcp_customers, name order, searchable by typing (a native select). */
function customer_select(PDO $pdo, string $name, ?int $selected, string $attrs = '', ?string $empty = 'Choose a customer…', string $id = ''): string
{
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    $h = '<select name="' . e($name) . '" id="' . e($id) . '" class="form-select btn-touch"' . ($attrs !== '' ? ' ' . $attrs : '') . '>';
    if ($empty !== null) {
        $h .= '<option value="">' . e($empty) . '</option>';
    }
    foreach (customers_for_select($pdo) as $c) {
        $h .= '<option value="' . $c['customer_id'] . '"' . ($selected === $c['customer_id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    }
    return $h . '</select>';
}

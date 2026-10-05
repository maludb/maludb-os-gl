<?php
declare(strict_types=1);
/** The ONE party picker (slice 2): party_select($pdo, 'customer'|'vendor', $name, $selected, $attrs, $empty, $id) over mcp_customers / mcp_vendors (live ones). */
function party_select(PDO $pdo, string $kind, string $name, ?int $selected, string $attrs = '', ?string $empty = 'None', string $id = ''): string
{
    $view = $kind === 'vendor' ? 'mcp_vendors' : 'mcp_customers';
    $col = $kind === 'vendor' ? 'vendor_id' : 'customer_id';
    $id = $id !== '' ? $id : 'field-' . preg_replace('/[^a-z0-9_-]/', '-', $name);
    $h = '<select name="' . e($name) . '" id="' . e($id) . '" class="form-select form-select-sm"' . ($attrs !== '' ? ' ' . $attrs : '') . '>';
    if ($empty !== null) {
        $h .= '<option value="">' . e($empty) . '</option>';
    }
    try {
        foreach ($pdo->query("SELECT {$col} AS id, name FROM {$view} WHERE archived_at IS NULL ORDER BY lower(name)")->fetchAll() as $p) {
            $h .= '<option value="' . (int) $p['id'] . '"' . ($selected === (int) $p['id'] ? ' selected' : '') . '>' . e($p['name']) . '</option>';
        }
    } catch (PDOException) {
        // the parties' views exist from Phase 0; nothing to list is not an error
    }
    return $h . '</select>';
}

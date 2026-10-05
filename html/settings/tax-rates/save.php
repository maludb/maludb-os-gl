<?php
declare(strict_types=1);
/** Action `tax_rate_save` (by the presence of `tax_rate`; log `tax_rate.save` before/after of the changed fields): settings.manage. One default at a time. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('tax_rate') ?? request_integer('tax_rate_id');
$cur = $id === null ? null : (find_tax_rate($pdo, $id) ?? refuse(404, 'Tax rate not found.'));
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 60) {
    $errors['name'] = 'Give the rate a name of up to 60 characters.';
}
$rate = $cur['rate'] ?? null;
if (req_has('rate') || $cur === null) {
    $raw = str_replace('%', '', (string) req_val('rate'));
    if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw >= 100) {
        $errors['rate'] = 'The rate is a percentage from 0 to below 100 (8.25).';
    } else {
        $rate = number_format((float) $raw, 4, '.', '');
    }
}
$isDefault = gl_yes('is_default', $cur['is_default'] ?? false);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$f = ['name' => $name, 'rate' => $rate, 'is_default' => $isDefault];
$newId = gl_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_tax_rate($pdo, $id, $f);
    $after = array_intersect_key(find_tax_rate($pdo, $newId), array_flip(['name', 'rate', 'is_default']));
    if ($cur === null) {
        log_activity($pdo, 'tax_rate.save', 'tax_rate', $newId, ['after' => $after]);
    } else {
        $d = gl_diff(array_intersect_key($cur, array_flip(['name', 'rate', 'is_default'])), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'tax_rate.save', 'tax_rate', $newId, ['before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
gl_done(($id === null ? 'Added the rate ' : 'Saved the rate ') . $f['name'], $newId, gl_land(return_path('/settings/tax-rates/'), 'saved', 'tax-rate-row-' . $newId), 'taxRateChanged', ['tax_rate_id' => $newId]);

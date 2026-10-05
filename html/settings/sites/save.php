<?php
declare(strict_types=1);
/** Action `site_save` (by the presence of `site`; log `site.save` before/after of the changed fields): settings.manage. A field left out stays as it was. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/accounts/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/ledger.php';
require_once dirname(__DIR__, 3) . '/app/features/settings/present.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$id = request_integer('site') ?? request_integer('site_id');
$cur = $id === null ? null : (find_site($pdo, $id) ?? refuse(404, 'Site not found.'));
$errors = [];
$name = req_has('name') ? (string) req_val('name') : (string) ($cur['name'] ?? '');
if ($name === '' || mb_strlen($name) > 120) {
    $errors['name'] = 'Give the site a name of up to 120 characters.';
}
$locField = req_has('kernel_location') ? 'kernel_location' : 'kernel_location_id';
$loc = gl_int($locField, $cur['kernel_location_id'] ?? null, 1, PHP_INT_MAX, "The OS location id", $errors, true);
$address = req_has('address') ? ((string) req_val('address') === '' ? null : mb_substr((string) req_val('address'), 0, 500)) : ($cur['address'] ?? null);
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$f = ['name' => $name, 'kernel_location_id' => $loc, 'address' => $address];
$newId = gl_guard($pdo, static function () use ($pdo, $id, $cur, $f): int {
    $pdo->beginTransaction();
    $newId = save_site($pdo, $id, $f);
    $after = array_intersect_key(find_site($pdo, $newId), array_flip(['name', 'kernel_location_id', 'address']));
    if ($cur === null) {
        log_activity($pdo, 'site.save', 'site', $newId, ['site_id' => $newId, 'after' => $after]);
    } else {
        $d = gl_diff(array_intersect_key($cur, array_flip(['name', 'kernel_location_id', 'address'])), $after);
        if ($d['after'] !== []) {
            log_activity($pdo, 'site.save', 'site', $newId, ['site_id' => $newId, 'before' => $d['before'], 'after' => $d['after']]);
        }
    }
    $pdo->commit();
    return $newId;
});
gl_done(($id === null ? 'Added the site ' : 'Saved the site ') . $f['name'], $newId, gl_land(return_path('/settings/sites/'), 'saved', 'site-card-' . $newId), 'siteChanged', ['site_id' => $newId]);

<?php
declare(strict_types=1);
/** Action `fiscal_year_create` (log `fiscal_year.create`: after.name, start, end, periods; confirm): settings.manage. gl_fiscal_year_create() is the referee (the first of a month; never an overlap). */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/features/periods/queries.php';
gl_handler_begin();
require_right('settings.manage');
$pdo = db();
$me = (int) current_member_id();
$errors = [];
$start = request_date('start');
if ($start === null || $start === false) {
    $errors['start'] = 'The start is the first day of a month (YYYY-MM-DD).';
}
$name = (string) req_val('name');
if (mb_strlen($name) > 40) {
    $errors['name'] = 'The name is up to 40 characters.';
}
if ($errors !== []) {
    gl_refuse_fields($errors);
}
$y = gl_guard($pdo, static function () use ($pdo, $me, $start, $name): array {
    $pdo->beginTransaction();
    $id = create_fiscal_year($pdo, $start, $name, $me);
    $y = find_fiscal_year($pdo, $id);
    log_activity($pdo, 'fiscal_year.create', 'fiscal_year', $id, ['after' => ['name' => $y['name'], 'start' => $y['start_date'], 'end' => $y['end_date'], 'periods' => count($y['periods'])]]);
    $pdo->commit();
    return $y;
});
gl_done('Opened ' . $y['name'] . ' with ' . count($y['periods']) . ' periods', $y['fiscal_year_id'], gl_land(return_path('/periods/'), 'created', 'fiscal-year-card-' . $y['fiscal_year_id']), 'periodChanged', ['fiscal_year_id' => $y['fiscal_year_id'], 'name' => $y['name']]);

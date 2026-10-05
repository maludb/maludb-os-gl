<?php /** The shared period picker: a period or year, custom from/to (as_of for a balance sheet or trial balance), compare to, department and site (P&L), and the export links. Data: action, range, periods, asOf (bool: use as_of), mode (null: no compare), dept, site, departments, sites, formats */
$mode = $mode ?? null; $asOfOnly = !empty($asOf); ?>
<form method="get" action="<?= e($action) ?>" hx-get="<?= e($action) ?>" hx-target="#page-content" hx-push-url="true" id="period-picker" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
    <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="period-picker-period">Period</label>
        <select name="period" id="period-picker-period" class="form-select btn-touch"><option value="custom" <?= $range['custom'] ? 'selected' : '' ?>>Custom dates</option><?php foreach ($periods as $p): ?><option value="<?= e($p['value']) ?>" <?= $range['period'] === $p['value'] ? 'selected' : '' ?>><?= e($p['label']) ?></option><?php endforeach; ?></select></div>
    <?php if ($asOfOnly): ?>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="period-picker-as-of">As of</label><input type="date" name="as_of" id="period-picker-as-of" class="form-control btn-touch" value="<?= e($range['as_of']) ?>"></div>
    <?php else: ?>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="period-picker-from">From</label><input type="date" name="from" id="period-picker-from" class="form-control btn-touch" value="<?= e($range['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="period-picker-to">To</label><input type="date" name="to" id="period-picker-to" class="form-control btn-touch" value="<?= e($range['to']) ?>"></div>
    <?php endif; ?>
    <?php if ($mode !== null): ?>
        <div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="period-picker-compare">Compare to</label><select name="compare" id="period-picker-compare" class="form-select btn-touch"><?php foreach (COMPARE_MODES as $k => $label): ?><option value="<?= e($k) ?>" <?= $mode === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?php if (!empty($departments)): ?>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="period-picker-department">Department</label><select name="department" id="period-picker-department" class="form-select btn-touch"><option value="">All</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= ($dept ?? null) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="period-picker-site">Site</label><select name="site" id="period-picker-site" class="form-select btn-touch"><option value="">All</option><?php foreach ($sites as $s): ?><option value="<?= (int) $s['site_id'] ?>" <?= ($site ?? null) === (int) $s['site_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <?= $extraFields ?? '' ?>
    <div class="col-12 col-md-auto d-flex gap-2"><button type="submit" class="btn btn-primary btn-touch" id="period-picker-apply-btn">Apply</button>
        <?php $q = $_GET; unset($q['format']); ?>
        <a href="<?= e($action . '?' . http_build_query($q + ['format' => 'csv'])) ?>" class="btn btn-light btn-touch" id="period-picker-csv-link"><i class="feather-download me-1"></i>CSV</a>
        <a href="<?= e($action . '?' . http_build_query($q + ['format' => 'print'])) ?>" class="btn btn-light btn-touch" id="period-picker-print-link" target="_blank" rel="noopener"><i class="feather-printer me-1"></i>Print</a></div>
</div></form>

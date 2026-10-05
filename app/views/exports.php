<?php /** The exports (screen `exports`). Data: range, counts, periods */
$qs = static fn (array $extra): string => '/exports?' . http_build_query(['period' => $range['period'], 'from' => $range['from'], 'to' => $range['to'], 'as_of' => $range['as_of']] + $extra); ?>
<?= view('shared/header.php', ['id' => 'exports', 'title' => 'Exports', 'crumbs' => [['Home', '/'], ['Exports', null]], 'action' => '<a class="btn btn-primary btn-touch" href="' . e($qs(['format' => 'zip'])) . '" id="exports-zip-link"><i class="feather-download me-1"></i>Download all (ZIP)</a>']) ?>
<div class="main-content" id="exports-content">
    <form method="get" action="/exports" hx-get="/exports" hx-target="#page-content" hx-push-url="true" id="exports-picker" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="exports-period">Period</label>
            <select name="period" id="exports-period" class="form-select btn-touch"><option value="custom" <?= $range['custom'] ? 'selected' : '' ?>>Custom dates</option><?php foreach ($periods as $p): ?><option value="<?= e($p['value']) ?>" <?= $range['period'] === $p['value'] ? 'selected' : '' ?>><?= e($p['label']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="exports-from">From</label><input type="date" name="from" id="exports-from" class="form-control btn-touch" value="<?= e($range['from']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="exports-to">To</label><input type="date" name="to" id="exports-to" class="form-control btn-touch" value="<?= e($range['to']) ?>"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="exports-apply-btn">Apply</button></div>
    </div></form>
    <div class="fs-12 text-muted mb-3" id="exports-note">Files for <?= e($range['label']) ?>: balances and aging as of <?= e(format_date($range['as_of'])) ?>. Amounts are in each document's own currency; the statements are in the base currency. Every download is logged.</div>
    <div class="row g-3" id="exports-cards">
        <?php foreach (EXPORT_FILES as $slug => $what): ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="export-<?= e($slug) ?>"><div class="card-body p-3">
                <div class="fw-bold"><?= e(export_filename($slug, $range)) ?></div>
                <div class="fs-12 text-muted"><?= e($what) ?></div>
                <div class="fs-12 mt-1" id="export-<?= e($slug) ?>-rows"><?= isset($counts[$slug]) ? number_format($counts[$slug]) . ' row' . ($counts[$slug] === 1 ? '' : 's') : 'A statement' ?></div>
                <a class="btn btn-light btn-touch w-100 mt-2" href="<?= e($qs(['file' => $slug, 'format' => 'csv'])) ?>" id="export-<?= e($slug) ?>-link"><i class="feather-download me-1"></i>CSV</a>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>

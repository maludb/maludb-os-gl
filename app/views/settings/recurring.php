<?php /** The recurring templates (screen `recurring-list`). Data: rows, filters, may, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'recurring-list', 'title' => 'Recurring', 'crumbs' => [['Home', '/'], ['Settings', '/settings/'], ['Recurring', null]], 'action' => hx_link('/settings/recurring/new', '<i class="feather-plus me-1"></i>Add a template', 'btn btn-primary btn-touch', 'id="recurring-add-btn"')]) ?>
<div class="main-content" id="recurring-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/settings/recurring/" class="row g-2 mb-3" id="recurring-filters">
        <div class="col-12 col-md-4"><input type="search" name="q" class="form-control btn-touch" placeholder="Name" value="<?= e($filters['q']) ?>" id="recurring-filter-q"></div>
        <div class="col-6 col-md-3"><select name="kind" class="form-select btn-touch" id="recurring-filter-kind"><option value="">Every kind</option><?php foreach (TEMPLATE_KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><select name="active" class="form-select btn-touch" id="recurring-filter-active"><option value="">On and off</option><option value="yes" <?= $filters['active'] === 'yes' ? 'selected' : '' ?>>On</option><option value="no" <?= $filters['active'] === 'no' ? 'selected' : '' ?>>Off</option></select></div>
        <div class="col-12 col-md-2"><button class="btn btn-light btn-touch w-100" id="recurring-filter-btn">Filter</button></div>
    </form>
    <div class="row g-3" id="recurring-cards">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="recurring-empty">No recurring template yet. A template drafts a journal entry, a bill or an invoice on a schedule; a person posts, approves or issues what it drafts.</div></div></div><?php endif; ?>
        <?php foreach ($rows as $t): $id = $t['recurring_template_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100<?= $t['active'] ? '' : ' opacity-75' ?>" id="recurring-<?= $id ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><?= hx_link(with_back('/settings/recurring/' . $id, $here), e($t['name']), 'text-dark') ?></div><span class="badge <?= $t['active'] ? 'bg-soft-success text-success' : 'bg-soft-secondary text-secondary' ?>"><?= $t['active'] ? 'On' : 'Off' ?></span></div>
                <div class="fs-12 text-muted"><?= e(TEMPLATE_KINDS[$t['kind']]) ?> · <?= e(cadence_words($t['cadence'], $t['every_n'])) ?></div>
                <div class="fs-12 mt-1">Next <?= e(format_date($t['next_date'])) ?><?= $t['end_date'] !== null ? ' · ends ' . e(format_date($t['end_date'])) : '' ?></div>
                <div class="fs-12 text-muted"><?= $t['run_count'] ?> run<?= $t['run_count'] === 1 ? '' : 's' ?><?= $t['last_run_at'] !== null ? ', last ' . e(format_date(substr((string) $t['last_run_at'], 0, 10))) : '' ?></div>
                <?php if (($t['last_status'] ?? null) === 'failed'): ?><div class="fs-12 text-danger mt-1" id="recurring-<?= $id ?>-failed">Last run failed: <?= e((string) $t['last_detail']) ?></div><?php endif; ?>
                <div class="row g-2 mt-2">
                    <div class="col-6"><?= hx_link(with_back('/settings/recurring/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="recurring-' . $id . '-open-btn"') ?></div>
                    <div class="col-6"><form method="post" action="/settings/recurring/toggle.php" hx-post="/settings/recurring/toggle.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="template" value="<?= $id ?>"><input type="hidden" name="active" value="<?= $t['active'] ? 'no' : 'yes' ?>"><button class="btn btn-light btn-touch w-100" id="recurring-<?= $id ?>-toggle-btn"><?= $t['active'] ? 'Turn off' : 'Turn on' ?></button></form></div>
                </div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>

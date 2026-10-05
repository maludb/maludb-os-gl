<?php /** The chart of accounts (screen `accounts-list`). Data: groups, filters, currency, may, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'accounts-list', 'title' => 'Chart of accounts', 'crumbs' => [['Home', '/'], ['Chart of accounts', null]],
    'action' => $may['manage'] ? hx_link('/accounts/new', '<i class="feather-plus me-1"></i>Add account', 'btn btn-primary btn-touch', 'id="accounts-list-add-btn"') : '']) ?>
<div class="main-content" id="accounts-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/accounts/" hx-get="/accounts/" hx-target="#accounts-results" hx-swap="outerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#accounts-filter-q" hx-push-url="true" id="accounts-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-4"><label class="form-label fs-12 text-muted" for="accounts-filter-q">Code or name</label><input type="search" name="q" id="accounts-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>" placeholder="1200 or Receivable"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="accounts-filter-type">Type</label><select name="type" id="accounts-filter-type" class="form-select btn-touch"><option value="">Every type</option><?php foreach (ACCOUNT_TYPES as $t => $label): ?><option value="<?= e($t) ?>" <?= $filters['type'] === $t ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="accounts-filter-as-of">Balance as of</label><input type="date" name="as_of" id="accounts-filter-as-of" class="form-control btn-touch" value="<?= e($filters['as_of']) ?>"></div>
        <div class="col-12 col-md-2"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="accounts-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="include_archived" value="1" id="accounts-filter-archived" <?= $filters['include_archived'] ? 'checked' : '' ?>>Archived too</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?= view('accounts/partials/groups.php', ['groups' => $groups, 'filters' => $filters, 'currency' => $currency, 'may' => $may, 'here' => $here]) ?>
</div>

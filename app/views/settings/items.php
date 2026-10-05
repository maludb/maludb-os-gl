<?php /** Products and services (screen `items-list`, cards). Data: rows, filters, currency, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'items-list', 'title' => 'Items', 'crumbs' => [['Home', '/'], ['Ledger settings', '/settings/general'], ['Items', null]],
    'action' => hx_link('/settings/items/new', '<i class="feather-plus me-1"></i>Add item', 'btn btn-primary btn-touch', 'id="items-list-add-btn"')]) ?>
<div class="main-content" id="items-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <form method="get" action="/settings/items/" hx-get="/settings/items/" hx-target="#items-results" hx-swap="outerHTML" hx-trigger="change, submit, keyup changed delay:400ms from:#items-filter-q" hx-push-url="true" id="items-filters" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="items-filter-q">Name or code</label><input type="search" name="q" id="items-filter-q" class="form-control btn-touch" value="<?= e($filters['q']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="items-filter-kind">Kind</label><select name="kind" id="items-filter-kind" class="form-select btn-touch"><option value="">Both</option><?php foreach (ITEM_KINDS as $k => $label): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-3"><label class="d-flex align-items-center gap-2 border rounded px-3 btn-touch mb-0" for="items-filter-archived"><input type="checkbox" class="form-check-input mt-0" name="include_archived" value="1" id="items-filter-archived" <?= $filters['include_archived'] ? 'checked' : '' ?>>Archived too</label></div>
        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
    </div></form>
    <?= view('settings/partials/item-cards.php', ['rows' => $rows, 'currency' => $currency, 'here' => $here]) ?>
</div>

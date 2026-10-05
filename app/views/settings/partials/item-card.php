<?php /** One item as a card. Data: i, currency, here */ $id = (int) $i['item_id']; ?>
<div class="card h-100<?= $i['archived_at'] !== null ? ' opacity-75' : '' ?>" id="item-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0" id="item-card-<?= $id ?>-name"><?= hx_link(with_back('/settings/items/' . $id . '/edit', $here), e($i['name']), 'text-dark') ?><?= $i['code'] !== null ? ' <span class="fs-12 text-muted">' . e($i['code']) . '</span>' : '' ?></div>
        <div class="text-nowrap fw-semibold" id="item-card-<?= $id ?>-price"><?= e(fmt_money($i['unit_price'], $currency)) ?><?= $i['unit'] !== null ? '<span class="fs-12 text-muted">/' . e($i['unit']) . '</span>' : '' ?></div>
    </div>
    <div class="mt-1"><span class="badge bg-soft-secondary text-secondary" id="item-card-<?= $id ?>-kind"><?= e(ITEM_KINDS[$i['kind']] ?? $i['kind']) ?></span><?php if ($i['archived_at'] !== null): ?> <span class="badge bg-soft-danger text-danger">Archived</span><?php endif; ?></div>
    <div class="fs-12 text-muted mt-1" id="item-card-<?= $id ?>-accounts">
        <?= $i['income_account_id'] !== null ? 'Sold to ' . e($i['income_account_code']) : 'No income account' ?> · <?= $i['expense_account_id'] !== null ? 'bought to ' . e($i['expense_account_code']) : 'no expense account' ?><?= $i['tax_rate_id'] !== null ? ' · ' . e($i['tax_rate_name']) : '' ?>
    </div>
    <div class="row g-2 mt-2">
        <div class="col-6"><?= hx_link(with_back('/settings/items/' . $id . '/edit', $here), 'Change', 'btn btn-light btn-touch w-100', 'id="item-card-' . $id . '-edit-btn"') ?></div>
        <div class="col-6"><form method="post" action="/settings/items/archive.php" hx-post="/settings/items/archive.php" hx-target="#flash" <?= $i['archived_at'] === null ? 'hx-confirm="Archive ' . e($i['name']) . '? It leaves the pickers."' : '' ?>><?= csrf_field() ?><input type="hidden" name="item" value="<?= $id ?>"><input type="hidden" name="archived" value="<?= $i['archived_at'] === null ? 'yes' : 'no' ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="item-card-<?= $id ?>-archive-btn"><?= $i['archived_at'] === null ? 'Archive' : 'Restore' ?></button></form></div>
    </div>
</div></div>

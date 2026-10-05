<?php /** One account as a card (RecordCard). Data: a, currency, may (manage), here */ $id = (int) $a['account_id']; $sub = ACCOUNT_SUBTYPES[$a['account_type']][$a['subtype']] ?? $a['subtype']; ?>
<div class="card h-100<?= $a['parent_id'] !== null ? ' ms-3' : '' ?><?= $a['archived_at'] !== null ? ' opacity-75' : '' ?>" id="account-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0" id="account-card-<?= $id ?>-name"><?= hx_link(with_back('/accounts/' . $id, $here), '<span class="text-muted me-1">' . e($a['code']) . '</span>' . e($a['name']), 'text-dark') ?></div>
        <div class="text-end text-nowrap fw-semibold" id="account-card-<?= $id ?>-balance"><?= e(fmt_money($a['balance'], $currency)) ?></div>
    </div>
    <div class="mt-1" id="account-card-<?= $id ?>-chips">
        <span class="badge bg-soft-secondary text-secondary"><?= e($sub) ?></span>
        <?php if ($a['is_bank']): ?><span class="badge bg-soft-primary text-primary">Bank</span><?php endif; ?>
        <?php if ($a['is_control']): ?><span class="badge bg-soft-warning text-warning">Control</span><?php endif; ?>
        <?php if ($a['is_system']): ?><span class="badge bg-soft-dark text-dark">System</span><?php endif; ?>
        <?php if ($a['archived_at'] !== null): ?><span class="badge bg-soft-danger text-danger">Archived</span><?php endif; ?>
        <?php if ($a['parent_id'] !== null): ?><span class="fs-12 text-muted">under <?= e($a['parent_code']) ?></span><?php endif; ?>
    </div>
    <div class="row g-2 mt-2">
        <div class="col-<?= $may['manage'] ? '6' : '12' ?>"><?= hx_link(with_back('/accounts/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="account-card-' . $id . '-open-btn"') ?></div>
        <?php if ($may['manage']): ?><div class="col-6"><?= hx_link(with_back('/accounts/' . $id . '/edit', $here), 'Change', 'btn btn-light btn-touch w-100', 'id="account-card-' . $id . '-edit-btn"') ?></div><?php endif; ?>
    </div>
</div></div>

<?php /** One vendor as a card. Data: v, may, here */ $id = (int) $v['vendor_id']; $kindIcon = ['ai_provider' => 'feather-cpu', 'employee' => 'feather-user', 'tax_authority' => 'feather-landmark']; ?>
<div class="card h-100<?= $v['archived_at'] !== null ? ' opacity-75' : '' ?>" id="vendor-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0" id="vendor-card-<?= $id ?>-name"><?= hx_link(with_back('/vendors/' . $id, $here), e($v['name']), 'text-dark') ?></div>
        <span class="d-flex gap-1"><?php if ($v['kind'] !== 'vendor'): ?><span class="badge bg-soft-<?= $v['kind'] === 'ai_provider' ? 'info text-info' : 'secondary text-secondary' ?>" id="vendor-card-<?= $id ?>-kind"><i class="<?= e($kindIcon[$v['kind']] ?? 'feather-tag') ?> me-1"></i><?= e(VENDOR_KINDS[$v['kind']] ?? $v['kind']) ?></span><?php endif; ?>
        <?php if ($v['archived_at'] !== null): ?><span class="badge bg-soft-danger text-danger">Archived</span><?php endif; ?></span>
    </div>
    <div class="mt-1 fs-12 text-muted" id="vendor-card-<?= $id ?>-email"><?= $v['email'] !== null ? '<i class="feather-mail me-1"></i>' . e($v['email']) : '' ?></div>
    <div class="mt-2" id="vendor-card-<?= $id ?>-balance"><?php if ($v['balances'] === []): ?><span class="text-muted fs-12">Nothing owed</span><?php else: foreach ($v['balances'] as $b): ?><div><span class="fw-bold"><?= e(fmt_money($b['balance'], $b['currency'])) ?></span> <span class="fs-12 text-muted">owed</span></div><?php endforeach; endif; ?></div>
    <div class="fs-12 text-muted" id="vendor-card-<?= $id ?>-facts"><?= (int) $v['open_bills'] ?> open bill<?= (int) $v['open_bills'] === 1 ? '' : 's' ?><?= $v['last_billed_on'] !== null ? ' · last billed ' . e(format_date($v['last_billed_on'])) : '' ?></div>
    <div class="row g-2 mt-2">
        <div class="col-<?= $may['write'] ? '6' : '12' ?>"><?= hx_link(with_back('/vendors/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="vendor-card-' . $id . '-open-btn"') ?></div>
        <?php if ($may['write']): ?><div class="col-6"><?= hx_link(with_back('/bills/new?vendor=' . $id, $here), 'New bill', 'btn btn-light btn-touch w-100', 'id="vendor-card-' . $id . '-bill-btn"') ?></div><?php endif; ?>
    </div>
</div></div>

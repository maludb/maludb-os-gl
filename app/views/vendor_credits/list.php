<?php /** The vendor credits (screen `vendor-credits-list`). Data: rows, filters, may, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'vendor-credits-list', 'title' => 'Vendor credits', 'crumbs' => [['Home', '/'], ['Vendor credits', null]],
    'action' => $may['write'] ? hx_link('/vendor-credits/new', '<i class="feather-plus me-1"></i>New vendor credit', 'btn btn-primary btn-touch', 'id="vendor-credits-list-add-btn"') : '']) ?>
<div class="main-content" id="vendor-credits-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3 d-flex flex-wrap gap-2" id="vendor-credits-tabs"><?php foreach (['' => 'All', 'draft' => 'Draft', 'issued' => 'Issued', 'void' => 'Void'] as $k => $label): ?><?= hx_link('/vendor-credits/' . ($k === '' ? '' : '?status=' . $k), $label, 'btn btn-sm ' . ($filters['status'] === $k ? 'btn-primary' : 'btn-light'), 'id="vendor-credits-tab-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?></div>
    <div class="row g-3" id="vendor-credits-list-cards">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="vendor-credits-empty">No vendor credit.</div></div></div><?php endif; ?>
        <?php foreach ($rows as $c): $id = (int) $c['vendor_credit_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="vendor-credit-card-<?= $id ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><?= hx_link(with_back('/vendor-credits/' . $id, $here), e($c['number']), 'text-dark') ?></div><span class="badge bg-<?= VENDOR_CREDIT_STATUS_BADGE[$c['status']] ?>"><?= e($c['status']) ?></span></div>
                <div class="fs-12 text-muted"><?= e($c['vendor_name']) ?> · <?= e(format_date($c['credit_date'])) ?><?= $c['bill_number'] ? ' · against ' . e($c['bill_number']) : '' ?></div>
                <div class="mt-1 fw-semibold"><?= e(fmt_money($c['total'], $c['currency'])) ?><?= $c['status'] === 'issued' ? ' <span class="fs-12 text-muted fw-normal">' . e(fmt_money($c['balance'], $c['currency'], false)) . ' unapplied</span>' : '' ?></div>
                <div class="mt-2"><?= hx_link(with_back('/vendor-credits/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="vendor-credit-card-' . $id . '-open-btn"') ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>

<?php /** One customer as a card. Data: c, may, here */ $id = (int) $c['customer_id']; ?>
<div class="card h-100<?= $c['archived_at'] !== null ? ' opacity-75' : '' ?>" id="customer-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="fw-bold min-w-0" id="customer-card-<?= $id ?>-name"><?= hx_link(with_back('/customers/' . $id, $here), e($c['name']), 'text-dark') ?></div>
        <?php if ($c['archived_at'] !== null): ?><span class="badge bg-soft-danger text-danger">Archived</span><?php endif; ?>
    </div>
    <div class="mt-1 fs-12 text-muted" id="customer-card-<?= $id ?>-email"><?= $c['email'] !== null ? '<i class="feather-mail me-1"></i>' . e($c['email']) : '' ?></div>
    <div class="mt-2" id="customer-card-<?= $id ?>-balance"><?php if ($c['balances'] === []): ?><span class="text-muted fs-12">Nothing owed</span><?php else: foreach ($c['balances'] as $b): ?><div><span class="fw-bold"><?= e(fmt_money($b['balance'], $b['currency'])) ?></span> <span class="fs-12 text-muted">due</span></div><?php endforeach; endif; ?></div>
    <div class="fs-12 text-muted" id="customer-card-<?= $id ?>-facts"><?= (int) $c['open_invoices'] ?> open invoice<?= (int) $c['open_invoices'] === 1 ? '' : 's' ?><?= $c['last_invoiced_on'] !== null ? ' · last invoiced ' . e(format_date($c['last_invoiced_on'])) : '' ?></div>
    <div class="row g-2 mt-2">
        <div class="col-<?= $may['write'] ? '6' : '12' ?>"><?= hx_link(with_back('/customers/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="customer-card-' . $id . '-open-btn"') ?></div>
        <?php if ($may['write']): ?><div class="col-6"><?= hx_link(with_back('/invoices/new?customer=' . $id, $here), 'New invoice', 'btn btn-light btn-touch w-100', 'id="customer-card-' . $id . '-invoice-btn"') ?></div><?php endif; ?>
    </div>
</div></div>

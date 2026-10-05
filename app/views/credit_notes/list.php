<?php /** The credit notes (screen `credit-notes-list`). Data: rows, filters, may, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'credit-notes-list', 'title' => 'Credit notes', 'crumbs' => [['Home', '/'], ['Credit notes', null]],
    'action' => $may['write'] ? hx_link('/credit-notes/new', '<i class="feather-plus me-1"></i>New credit note', 'btn btn-primary btn-touch', 'id="credit-notes-list-add-btn"') : '']) ?>
<div class="main-content" id="credit-notes-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="mb-3 d-flex flex-wrap gap-2" id="credit-notes-tabs"><?php foreach (['' => 'All', 'draft' => 'Draft', 'issued' => 'Issued', 'void' => 'Void'] as $k => $label): ?><?= hx_link('/credit-notes/' . ($k === '' ? '' : '?status=' . $k), $label, 'btn btn-sm ' . ($filters['status'] === $k ? 'btn-primary' : 'btn-light'), 'id="credit-notes-tab-' . ($k === '' ? 'all' : $k) . '"') ?><?php endforeach; ?></div>
    <div class="row g-3" id="credit-notes-list-cards">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="credit-notes-empty">No credit note.</div></div></div><?php endif; ?>
        <?php foreach ($rows as $c): $id = (int) $c['credit_note_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100" id="credit-note-card-<?= $id ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><?= hx_link(with_back('/credit-notes/' . $id, $here), $c['number'] !== null ? e($c['number']) : 'Draft #' . $id, 'text-dark') ?></div><span class="badge bg-<?= CREDIT_STATUS_BADGE[$c['status']] ?>"><?= e($c['status']) ?></span></div>
                <div class="fs-12 text-muted"><?= e($c['customer_name']) ?> · <?= e(format_date($c['issue_date'])) ?><?= $c['invoice_number'] ? ' · against ' . e($c['invoice_number']) : '' ?></div>
                <div class="mt-1 fw-semibold"><?= e(fmt_money($c['total'], $c['currency'])) ?><?= $c['status'] === 'issued' ? ' <span class="fs-12 text-muted fw-normal">' . e(fmt_money($c['balance'], $c['currency'], false)) . ' unapplied</span>' : '' ?></div>
                <div class="mt-2"><?= hx_link(with_back('/credit-notes/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="credit-note-card-' . $id . '-open-btn"') ?></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>

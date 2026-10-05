<?php /** One entry as a card (the phone). Data: e, here */ $id = (int) $e['journal_entry_id']; $url = with_back('/journal/' . $id, $here); ?>
<div class="card mb-2" id="entry-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <div class="min-w-0"><?= hx_link($url, $e['number'] !== null ? e($e['number']) : 'Draft #' . $id, 'fw-bold text-dark') ?><div class="fs-12 text-muted"><?= e(format_date($e['entry_date'])) ?> · <?= e($e['source_label']) ?><?= $e['period_name'] !== null ? ' · ' . e($e['period_name']) : ' · no period' ?></div></div>
        <div class="text-end text-nowrap fw-semibold"><?= e(fmt_money($e['total'], $e['currency'])) ?></div>
    </div>
    <?php if ($e['memo'] !== null): ?><div class="mt-1 text-truncate"><?= e($e['memo']) ?></div><?php endif; ?>
    <div class="mt-1"><span class="badge bg-<?= ENTRY_STATUS_BADGE[$e['status']] ?>"><?= e($e['status']) ?></span>
        <?php if ($e['status'] === 'draft'): ?> <span class="badge bg-soft-<?= $e['balances'] ? 'success text-success' : 'danger text-danger' ?>"><?= $e['balances'] ? 'balances' : ((float) $e['draft_debit'] == 0 && (float) $e['draft_credit'] == 0 ? 'no lines' : 'out by ' . number_format(abs((float) $e['difference']), 2)) ?></span><?php endif; ?>
        <?= $e['created_by_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?><?= (int) $e['attachment_count'] > 0 ? ' <i class="feather-paperclip fs-12 text-muted"></i>' : '' ?></div>
    <div class="mt-2"><?= hx_link($url, 'Open', 'btn btn-light btn-touch w-100', 'id="entry-card-' . $id . '-open-btn"') ?></div>
</div></div>

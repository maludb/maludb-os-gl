<?php /** One invoice (screen `invoice-view`). Data: inv, attachments, notes, timeline, may, here, tz, notice + the lines data (kind, doc, id, lines, editable, pdo, items, taxRates, departments, sites, currency, prefix, saveUrl, removeUrl, param) */
$id = (int) $inv['invoice_id']; $title = $inv['number'] ?? 'Draft invoice'; $st = $inv['status']; $open = in_array($st, OPEN_INVOICE_STATUSES, true); $draft = $st === 'draft'; ?>
<?= view('shared/header.php', ['id' => 'invoice-view', 'title' => $title, 'crumbs' => [['Home', '/'], ['Invoices', '/invoices/'], [$title, null]], 'back' => back_link() ?? ['/invoices/', 'invoices'],
    'action' => !$draft ? '<a class="btn btn-light btn-touch" href="/invoices/' . $id . '/print" target="_blank" rel="noopener" id="invoice-view-print-btn"><i class="feather-printer me-1"></i>Print</a>' : ($may['write'] ? hx_link(with_back('/invoices/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="invoice-view-edit-btn"') : '')]) ?>
<div class="main-content" id="invoice-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="invoice-head"><div class="card-body p-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-<?= INVOICE_STATUS_BADGE[$st] ?>" id="invoice-head-status"><?= e(INVOICE_STATUSES[$st] ?? $st) ?></span>
            <?php if ($inv['overdue']): ?><span class="badge bg-danger" id="invoice-head-overdue"><?= (int) $inv['days_overdue'] ?> days overdue</span><?php endif; ?>
            <?php if ((int) $inv['open_claims'] > 0): ?><span class="badge bg-soft-warning text-warning" id="invoice-head-claim">customer says they paid</span><?php endif; ?>
            <span class="ms-auto fw-bold fs-16" id="invoice-head-balance"><?= e(fmt_money($draft ? $inv['total'] : $inv['balance_due'], $inv['currency'])) ?><?= $draft ? '' : ' <span class="fs-12 text-muted fw-normal">due</span>' ?></span>
        </div>
        <div class="mt-2" id="invoice-head-customer"><?= hx_link(with_back('/customers/' . (int) $inv['customer_id'], $here), e($inv['customer_name']), 'fw-semibold text-dark') ?></div>
        <div class="fs-12 text-muted mt-1" id="invoice-head-dates">Issued <?= e(format_date($inv['issue_date'])) ?> · due <?= e(format_date($inv['due_date'])) ?><?= $inv['reference'] ? ' · their ref ' . e($inv['reference']) : '' ?><?= $inv['department_name'] ? ' · ' . e($inv['department_name']) : '' ?><?= $inv['site_name'] ? ' · ' . e($inv['site_name']) : '' ?></div>
        <?php if ($inv['memo']): ?><div class="mt-2" id="invoice-head-memo"><?= nl2br(e($inv['memo'])) ?></div><?php endif; ?>
        <?php if ($inv['journal_entry_id'] !== null): ?><div class="fs-12 mt-2" id="invoice-head-entry">Posted as <?= hx_link(with_back('/journal/' . (int) $inv['journal_entry_id'], $here), e((string) ($inv['journal_entry_number'] ?? 'the entry')), '', 'id="invoice-head-entry-link"') ?><?= $inv['write_off_entry_id'] !== null ? ' · written off in ' . hx_link(with_back('/journal/' . (int) $inv['write_off_entry_id'], $here), 'its entry') : '' ?></div><?php endif; ?>
        <?php if ($inv['void_reason']): ?><div class="fs-12 text-muted mt-1" id="invoice-head-void">Void: <?= e($inv['void_reason']) ?></div><?php endif; ?>
        <div class="fs-12 text-muted mt-1">By <?= e($inv['created_by_name'] ?? '?') ?><?= $inv['created_by_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?>, <?= e(format_ts($inv['created_at'], $tz)) ?></div>
    </div></div>
    <?= view('invoices/partials/lines.php', ['kind' => $kind, 'doc' => $doc, 'id' => $id, 'lines' => $lines, 'editable' => $editable, 'pdo' => $pdo, 'items' => $items, 'taxRates' => $taxRates, 'departments' => $departments, 'sites' => $sites, 'currency' => $currency, 'prefix' => $prefix, 'saveUrl' => $saveUrl, 'removeUrl' => $removeUrl, 'param' => $param]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <?= view('invoices/partials/totals.php', ['kind' => 'invoice', 'doc' => $inv, 'currency' => $currency, 'prefix' => 'invoice']) ?>
            <?php if ($draft && $may['write']): ?>
                <div class="card mb-3" id="invoice-actions"><div class="card-body">
                    <form method="post" action="/invoices/issue.php" hx-post="/invoices/issue.php" hx-target="#flash" hx-confirm="Issue this invoice? It is numbered and posted, and never changes afterwards."><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>">
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="invoice-issue-btn" <?= $lines === [] ? 'disabled' : '' ?>>Issue</button><?php if ($lines === []): ?><div class="fs-12 text-muted mt-1" id="invoice-issue-why">Add a line first.</div><?php endif; ?></form>
                    <form method="post" action="/invoices/delete.php" hx-post="/invoices/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Delete this draft? It burns no number."><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="invoice-delete-btn">Delete the draft</button></form>
                </div></div>
            <?php elseif (($open || $st === 'paid') && ($may['write'] || $may['override'])): ?>
                <div class="card mb-3" id="invoice-actions"><div class="card-body">
                    <?php if ($may['write']): ?>
                        <?php if ($open): ?><?= hx_link(with_back('/receipts/new?customer=' . (int) $inv['customer_id'] . '&invoice=' . $id, $here), 'Record a receipt', 'btn btn-primary btn-touch w-100 mb-2', 'id="invoice-receipt-btn"') ?><?php endif; ?>
                        <form method="post" action="/invoices/send.php" hx-post="/invoices/send.php" hx-target="#flash" hx-confirm="Queue this invoice, with its secure link, for the customer?" class="mb-2"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>">
                            <input type="email" name="to_email" class="form-control btn-touch mb-2" placeholder="To (empty: the customer's primary contact, else the customer)" maxlength="200" id="invoice-send-to">
                            <textarea name="message" class="form-control mb-2" rows="2" maxlength="2000" placeholder="A few words before the invoice (optional)" id="invoice-send-message"></textarea>
                            <button type="submit" class="btn btn-light btn-touch w-100" id="invoice-send-btn"><?= $inv['sent_at'] !== null ? 'Send again' : 'Send' ?></button></form>
                        <?php if ($inv['overdue']): ?><form method="post" action="/invoices/remind.php" hx-post="/invoices/remind.php" hx-target="#flash" hx-confirm="Queue a reminder?" class="mb-2"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="invoice-remind-btn">Remind (<?= (int) $inv['reminder_count'] ?> sent)</button></form><?php endif; ?>
                        <?php if ($open): ?><?= hx_link(with_back('/credit-notes/new?customer=' . (int) $inv['customer_id'] . '&invoice=' . $id, $here), 'Credit', 'btn btn-light btn-touch w-100 mb-2', 'id="invoice-credit-btn"') ?><?php endif; ?>
                    <?php endif; ?>
                    <?php if ($may['override'] && $open): ?>
                        <form method="post" action="/invoices/void.php" hx-post="/invoices/void.php" hx-target="#flash" hx-confirm="Void this invoice? Its entry is reversed." class="mb-2"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>">
                            <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is voided" maxlength="500" required id="invoice-void-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="invoice-void-btn">Void</button></form>
                        <form method="post" action="/invoices/write-off.php" hx-post="/invoices/write-off.php" hx-target="#flash" hx-confirm="Write off what is still owed?"><?= csrf_field() ?><input type="hidden" name="invoice" value="<?= $id ?>">
                            <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is written off" maxlength="500" required id="invoice-write-off-reason"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="invoice-write-off-btn">Write off</button></form>
                    <?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?= view('invoices/partials/link-state.php', ['inv' => $inv, 'may' => $may, 'tz' => $tz, 'here' => $here]) ?>
            <?= view('shared/attachments.php', ['type' => 'invoice', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'invoice']) ?>
            <?= view('shared/notes.php', ['type' => 'invoice', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'invoice']) ?>
        </div>
        <div class="col-lg-7">
            <?= view('invoices/partials/received.php', ['inv' => $inv, 'may' => $may, 'here' => $here]) ?>
            <?= view('invoices/partials/claims.php', ['inv' => $inv, 'may' => $may, 'here' => $here]) ?>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'invoice']) ?>
        </div>
    </div>
</div>

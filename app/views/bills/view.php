<?php /** One bill (screen `bill-view`). Data: bill, attachments, notes, timeline, may, here, tz, notice + the lines data (kind, doc, id, lines, editable, pdo, items, taxRates, departments, sites, currency, prefix, side, saveUrl, removeUrl, param) */
$id = (int) $bill['bill_id']; $title = $bill['number']; $st = $bill['status']; $open = in_array($st, OPEN_BILL_STATUSES, true); $draft = $st === 'draft'; $hasDoc = $bill['has_attachment']; ?>
<?= view('shared/header.php', ['id' => 'bill-view', 'title' => $title, 'crumbs' => [['Home', '/'], ['Bills', '/bills/'], [$title, null]], 'back' => back_link() ?? ['/bills/', 'bills'],
    'action' => $draft && $may['write'] ? hx_link(with_back('/bills/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="bill-view-edit-btn"') : '']) ?>
<div class="main-content" id="bill-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="bill-head"><div class="card-body p-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-<?= BILL_STATUS_BADGE[$st] ?>" id="bill-head-status"><?= e(BILL_STATUSES[$st] ?? $st) ?></span>
            <?php if ($bill['overdue']): ?><span class="badge bg-danger" id="bill-head-overdue"><?= (int) $bill['days_overdue'] ?> days overdue</span><?php endif; ?>
            <?php if (!$hasDoc && $st !== 'void'): ?><span class="badge bg-soft-warning text-warning" id="bill-head-nodoc"><i class="feather-file me-1"></i>no document</span><?php endif; ?>
            <?php if ($bill['source'] === 'ai_usage'): ?><span class="badge bg-soft-info text-info" id="bill-head-source"><i class="feather-cpu me-1"></i>AI statement</span><?php endif; ?>
            <span class="ms-auto fw-bold fs-16" id="bill-head-balance"><?= e(fmt_money($draft ? $bill['total'] : $bill['balance_due'], $bill['currency'])) ?><?= $draft ? '' : ' <span class="fs-12 text-muted fw-normal">owed</span>' ?></span>
        </div>
        <div class="mt-2" id="bill-head-vendor"><?= hx_link(with_back('/vendors/' . (int) $bill['vendor_id'], $here), e($bill['vendor_name']), 'fw-semibold text-dark') ?></div>
        <div class="fs-12 text-muted mt-1" id="bill-head-dates">Dated <?= e(format_date($bill['bill_date'])) ?> · due <?= e(format_date($bill['due_date'])) ?><?= $bill['vendor_ref'] ? ' · their invoice ' . e($bill['vendor_ref']) : '' ?><?= $bill['department_name'] ? ' · ' . e($bill['department_name']) : '' ?><?= $bill['site_name'] ? ' · ' . e($bill['site_name']) : '' ?></div>
        <?php if ($bill['memo']): ?><div class="mt-2" id="bill-head-memo"><?= nl2br(e($bill['memo'])) ?></div><?php endif; ?>
        <?php if ($bill['journal_entry_id'] !== null): ?><div class="fs-12 mt-2" id="bill-head-entry">Posted as <?= hx_link(with_back('/journal/' . (int) $bill['journal_entry_id'], $here), e((string) ($bill['journal_entry_number'] ?? 'the entry')), '', 'id="bill-head-entry-link"') ?></div><?php endif; ?>
        <?php if ($bill['void_reason']): ?><div class="fs-12 text-muted mt-1" id="bill-head-void">Void: <?= e($bill['void_reason']) ?></div><?php endif; ?>
        <div class="fs-12 text-muted mt-1">By <?= e($bill['created_by_name'] ?? '?') ?><?= ($bill['created_by_kind'] ?? '') === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?>, <?= e(format_ts($bill['created_at'], $tz)) ?><?= $bill['approved_at'] ? ' · approved by ' . e((string) $bill['approved_by_name']) . ', ' . e(format_ts($bill['approved_at'], $tz)) : '' ?></div>
    </div></div>
    <?= view('invoices/partials/lines.php', ['kind' => $kind, 'doc' => $doc, 'id' => $id, 'lines' => $lines, 'editable' => $editable, 'pdo' => $pdo, 'items' => $items, 'taxRates' => $taxRates, 'departments' => $departments, 'sites' => $sites, 'currency' => $currency, 'prefix' => $prefix, 'side' => $side, 'saveUrl' => $saveUrl, 'removeUrl' => $removeUrl, 'param' => $param]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <?= view('invoices/partials/totals.php', ['kind' => 'bill', 'doc' => $bill, 'currency' => $currency, 'prefix' => 'bill']) ?>
            <?php if ($draft && ($may['write'] || $may['approve'])): ?>
                <div class="card mb-3" id="bill-actions"><div class="card-body">
                    <?php if ($may['approve']): ?><form method="post" action="/bills/approve.php" hx-post="/bills/approve.php" hx-target="#flash" hx-confirm="Approve this bill? It is posted against the vendor's payable and never changes afterwards."><?= csrf_field() ?><input type="hidden" name="bill" value="<?= $id ?>">
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="bill-approve-btn" <?= $lines === [] ? 'disabled' : '' ?>>Approve</button><?php if ($lines === []): ?><div class="fs-12 text-muted mt-1" id="bill-approve-why">Add a line first.</div><?php elseif (!$hasDoc): ?><div class="fs-12 text-warning mt-1" id="bill-approve-nodoc">No document is attached; you can still approve it.</div><?php endif; ?></form>
                    <?php else: ?><div class="fs-12 text-muted" id="bill-awaiting-note">Awaiting approval by the controller.</div><?php endif; ?>
                    <?php if ($may['write']): ?><form method="post" action="/bills/delete.php" hx-post="/bills/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Delete this draft?"><?= csrf_field() ?><input type="hidden" name="bill" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="bill-delete-btn">Delete the draft</button></form><?php endif; ?>
                </div></div>
            <?php elseif (($open || $st === 'paid') && ($may['write'] || $may['override'])): ?>
                <div class="card mb-3" id="bill-actions"><div class="card-body">
                    <?php if ($may['write'] && $open): ?><?= hx_link(with_back('/payments/new?vendor=' . (int) $bill['vendor_id'] . '&bill=' . $id, $here), 'Prepare a payment', 'btn btn-primary btn-touch w-100 mb-2', 'id="bill-payment-btn"') ?><?= hx_link(with_back('/vendor-credits/new?vendor=' . (int) $bill['vendor_id'] . '&bill=' . $id, $here), 'Enter a credit', 'btn btn-light btn-touch w-100 mb-2', 'id="bill-credit-btn"') ?><?php endif; ?>
                    <?php if ($may['write']): ?><?= hx_link(with_back('/bills/new?copy_of=' . $id, $here), 'Copy', 'btn btn-light btn-touch w-100 mb-2', 'id="bill-copy-btn"') ?><?php endif; ?>
                    <?php if ($may['override'] && $open): ?><form method="post" action="/bills/void.php" hx-post="/bills/void.php" hx-target="#flash" hx-confirm="Void this bill? Its entry is reversed."><?= csrf_field() ?><input type="hidden" name="bill" value="<?= $id ?>">
                        <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is voided" maxlength="500" required id="bill-void-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="bill-void-btn">Void</button></form><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <div class="card mb-3" id="bill-document"><div class="card-header"><h5 class="card-title mb-0">The document</h5></div><div class="card-body fs-12 <?= $hasDoc ? 'text-muted' : 'text-warning' ?>" id="bill-document-note"><?= $hasDoc ? 'Attached below.' : 'No document is attached to this bill. Attach the vendor\'s invoice below.' ?></div></div>
            <?= view('shared/attachments.php', ['type' => 'bill', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'bill']) ?>
            <?= view('shared/notes.php', ['type' => 'bill', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'bill']) ?>
        </div>
        <div class="col-lg-7">
            <?= view('bills/partials/paid.php', ['bill' => $bill, 'may' => $may, 'here' => $here]) ?>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'bill']) ?>
        </div>
    </div>
</div>

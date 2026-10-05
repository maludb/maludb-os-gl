<?php /** One credit note (screen `credit-note-view`). Data: cn, targets, attachments, notes, timeline, may, here, tz, notice + the lines data */
$id = (int) $cn['credit_note_id']; $title = $cn['number'] ?? 'Draft credit note'; $st = $cn['status']; $draft = $st === 'draft'; ?>
<?= view('shared/header.php', ['id' => 'credit-note-view', 'title' => $title, 'crumbs' => [['Home', '/'], ['Credit notes', '/credit-notes/'], [$title, null]], 'back' => back_link() ?? ['/credit-notes/', 'credit notes'],
    'action' => $draft && $may['write'] ? hx_link(with_back('/credit-notes/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="credit-note-view-edit-btn"') : '']) ?>
<div class="main-content" id="credit-note-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="credit-note-head"><div class="card-body p-3">
        <div class="d-flex flex-wrap align-items-center gap-2"><span class="badge bg-<?= CREDIT_STATUS_BADGE[$st] ?>" id="credit-note-head-status"><?= e(CREDIT_STATUSES[$st]) ?></span><span class="ms-auto fw-bold fs-16" id="credit-note-head-total"><?= e(fmt_money($cn['total'], $cn['currency'])) ?></span></div>
        <div class="mt-2"><?= hx_link(with_back('/customers/' . (int) $cn['customer_id'], $here), e($cn['customer_name']), 'fw-semibold text-dark') ?></div>
        <div class="fs-12 text-muted mt-1"><?= e(format_date($cn['issue_date'])) ?><?= $cn['invoice_id'] !== null ? ' · against ' . hx_link(with_back('/invoices/' . (int) $cn['invoice_id'], $here), e((string) $cn['invoice_number']), '', 'id="credit-note-head-invoice-link"') : '' ?></div>
        <?php if ($cn['reason']): ?><div class="mt-2" id="credit-note-head-reason"><?= e($cn['reason']) ?></div><?php endif; ?>
        <?php if ($cn['journal_entry_id'] !== null): ?><div class="fs-12 mt-2">Posted as <?= hx_link(with_back('/journal/' . (int) $cn['journal_entry_id'], $here), e((string) ($cn['journal_entry_number'] ?? 'the entry')), '', 'id="credit-note-head-entry-link"') ?></div><?php endif; ?>
        <?php if ($cn['void_reason']): ?><div class="fs-12 text-muted mt-1" id="credit-note-head-void">Void: <?= e($cn['void_reason']) ?></div><?php endif; ?>
    </div></div>
    <?= view('invoices/partials/lines.php', ['kind' => $kind, 'doc' => $doc, 'id' => $id, 'lines' => $lines, 'editable' => $editable, 'pdo' => $pdo, 'items' => $items, 'taxRates' => $taxRates, 'departments' => $departments, 'sites' => $sites, 'currency' => $currency, 'prefix' => $prefix, 'saveUrl' => $saveUrl, 'removeUrl' => $removeUrl, 'param' => $param]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <?= view('invoices/partials/totals.php', ['kind' => 'credit_note', 'doc' => $cn, 'currency' => $currency, 'prefix' => 'credit-note']) ?>
            <?php if ($may['write'] && $draft): ?>
                <div class="card mb-3" id="credit-note-actions"><div class="card-body">
                    <form method="post" action="/credit-notes/issue.php" hx-post="/credit-notes/issue.php" hx-target="#flash" hx-confirm="Issue this credit note? It is numbered and posted, and never changes afterwards."><?= csrf_field() ?><input type="hidden" name="credit_note" value="<?= $id ?>">
                        <?php if ($cn['invoice_id'] !== null): ?><input type="hidden" name="apply_to" value="<?= (int) $cn['invoice_id'] ?>"><div class="fs-12 text-muted mb-2" id="credit-note-issue-apply">It is applied to <?= e((string) $cn['invoice_number']) ?> at once.</div><?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="credit-note-issue-btn" <?= $lines === [] ? 'disabled' : '' ?>>Issue</button></form>
                    <form method="post" action="/credit-notes/delete.php" hx-post="/credit-notes/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Delete this draft?"><?= csrf_field() ?><input type="hidden" name="credit_note" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="credit-note-delete-btn">Delete the draft</button></form>
                </div></div>
            <?php elseif ($st === 'issued'): ?>
                <div class="card mb-3" id="credit-note-actions"><div class="card-body">
                    <?php if ($may['write'] && $targets !== []): ?>
                        <form method="post" action="/credit-notes/apply.php" hx-post="/credit-notes/apply.php" hx-target="#flash" class="mb-2" id="credit-note-apply-form"><?= csrf_field() ?><input type="hidden" name="credit_note" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <label class="form-label fs-12 text-muted" for="credit-note-apply-invoice">Apply to</label><select name="invoice" id="credit-note-apply-invoice" class="form-select btn-touch mb-2"><?php foreach ($targets as $i): ?><option value="<?= $i['invoice_id'] ?>"><?= e((string) $i['number']) ?> — <?= e(fmt_money($i['balance_due'], $currency)) ?> owed</option><?php endforeach; ?></select>
                            <input type="text" inputmode="decimal" name="amount" class="form-control btn-touch mb-2" placeholder="Amount (empty: what fits)" id="credit-note-apply-amount"><button type="submit" class="btn btn-primary btn-touch w-100" id="credit-note-apply-btn">Apply</button></form>
                    <?php endif; ?>
                    <?php if ($may['override']): ?><form method="post" action="/credit-notes/void.php" hx-post="/credit-notes/void.php" hx-target="#flash" hx-confirm="Void this credit note? Its entry is reversed."><?= csrf_field() ?><input type="hidden" name="credit_note" value="<?= $id ?>">
                        <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is voided" maxlength="500" required id="credit-note-void-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="credit-note-void-btn">Void</button></form><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?= view('shared/attachments.php', ['type' => 'credit_note', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'credit-note']) ?>
            <?= view('shared/notes.php', ['type' => 'credit_note', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'credit-note']) ?>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="credit-note-applications"><div class="card-header"><h5 class="card-title mb-0">Applied to</h5></div>
                <?php if ($cn['applications'] === []): ?><div class="card-body text-muted" id="credit-note-applications-empty">Not applied to an invoice.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($cn['applications'] as $a): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="credit-note-application-<?= $a['invoice_id'] ?>"><span><?= hx_link(with_back('/invoices/' . $a['invoice_id'], $here), e((string) $a['invoice_number'])) ?></span>
                        <span class="d-flex align-items-center gap-2"><span><?= e(fmt_money($a['amount'], $currency)) ?></span>
                        <?php if ($may['write']): ?><form method="post" action="/credit-notes/unapply.php" hx-post="/credit-notes/unapply.php" hx-target="#flash" hx-confirm="Take this credit off the invoice?"><?= csrf_field() ?><input type="hidden" name="credit_note" value="<?= $id ?>"><input type="hidden" name="invoice" value="<?= $a['invoice_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light" id="credit-note-application-<?= $a['invoice_id'] ?>-unapply-btn">Unapply</button></form><?php endif; ?></span></div>
                <?php endforeach; ?></div><?php endif; ?>
            </div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'credit-note']) ?>
        </div>
    </div>
</div>

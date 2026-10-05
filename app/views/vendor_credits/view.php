<?php /** One vendor credit (screen `vendor-credit-view`). Data: vc, targets, attachments, notes, timeline, may, here, tz, notice + the lines data */
$id = (int) $vc['vendor_credit_id']; $title = $vc['number']; $st = $vc['status']; $draft = $st === 'draft'; ?>
<?= view('shared/header.php', ['id' => 'vendor-credit-view', 'title' => $title, 'crumbs' => [['Home', '/'], ['Vendor credits', '/vendor-credits/'], [$title, null]], 'back' => back_link() ?? ['/vendor-credits/', 'vendor credits'],
    'action' => $draft && $may['write'] ? hx_link(with_back('/vendor-credits/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="vendor-credit-view-edit-btn"') : '']) ?>
<div class="main-content" id="vendor-credit-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="vendor-credit-head"><div class="card-body p-3">
        <div class="d-flex flex-wrap align-items-center gap-2"><span class="badge bg-<?= VENDOR_CREDIT_STATUS_BADGE[$st] ?>" id="vendor-credit-head-status"><?= e(VENDOR_CREDIT_STATUSES[$st]) ?></span><span class="ms-auto fw-bold fs-16" id="vendor-credit-head-total"><?= e(fmt_money($vc['total'], $vc['currency'])) ?></span></div>
        <div class="mt-2"><?= hx_link(with_back('/vendors/' . (int) $vc['vendor_id'], $here), e($vc['vendor_name']), 'fw-semibold text-dark') ?></div>
        <div class="fs-12 text-muted mt-1"><?= e(format_date($vc['credit_date'])) ?><?= $vc['vendor_ref'] ? ' · their credit ' . e($vc['vendor_ref']) : '' ?><?= $vc['bill_id'] !== null ? ' · against ' . hx_link(with_back('/bills/' . (int) $vc['bill_id'], $here), e((string) $vc['bill_number']), '', 'id="vendor-credit-head-bill-link"') : '' ?></div>
        <?php if ($vc['reason']): ?><div class="mt-2" id="vendor-credit-head-reason"><?= e($vc['reason']) ?></div><?php endif; ?>
        <?php if ($vc['journal_entry_id'] !== null): ?><div class="fs-12 mt-2">Posted as <?= hx_link(with_back('/journal/' . (int) $vc['journal_entry_id'], $here), e((string) ($vc['journal_entry_number'] ?? 'the entry')), '', 'id="vendor-credit-head-entry-link"') ?></div><?php endif; ?>
        <?php if ($vc['void_reason']): ?><div class="fs-12 text-muted mt-1" id="vendor-credit-head-void">Void: <?= e($vc['void_reason']) ?></div><?php endif; ?>
    </div></div>
    <?= view('invoices/partials/lines.php', ['kind' => $kind, 'doc' => $doc, 'id' => $id, 'lines' => $lines, 'editable' => $editable, 'pdo' => $pdo, 'items' => $items, 'taxRates' => $taxRates, 'departments' => $departments, 'sites' => $sites, 'currency' => $currency, 'prefix' => $prefix, 'side' => $side, 'saveUrl' => $saveUrl, 'removeUrl' => $removeUrl, 'param' => $param]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <?= view('invoices/partials/totals.php', ['kind' => 'vendor_credit', 'doc' => $vc, 'currency' => $currency, 'prefix' => 'vendor-credit']) ?>
            <?php if ($draft && ($may['write'] || $may['approve'])): ?>
                <div class="card mb-3" id="vendor-credit-actions"><div class="card-body">
                    <?php if ($may['approve']): ?><form method="post" action="/vendor-credits/issue.php" hx-post="/vendor-credits/issue.php" hx-target="#flash" hx-confirm="Issue this vendor credit? It is posted and never changes afterwards."><?= csrf_field() ?><input type="hidden" name="vendor_credit" value="<?= $id ?>">
                        <?php if ($vc['bill_id'] !== null): ?><input type="hidden" name="apply_to" value="<?= (int) $vc['bill_id'] ?>"><div class="fs-12 text-muted mb-2" id="vendor-credit-issue-apply">It is applied to <?= e((string) $vc['bill_number']) ?> at once, as far as it fits.</div><?php endif; ?>
                        <button type="submit" class="btn btn-primary btn-touch w-100" id="vendor-credit-issue-btn" <?= $lines === [] ? 'disabled' : '' ?>>Issue</button></form>
                    <?php else: ?><div class="fs-12 text-muted" id="vendor-credit-awaiting-note">Awaiting issue by the controller.</div><?php endif; ?>
                    <?php if ($may['write']): ?><form method="post" action="/vendor-credits/delete.php" hx-post="/vendor-credits/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Delete this draft?"><?= csrf_field() ?><input type="hidden" name="vendor_credit" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="vendor-credit-delete-btn">Delete the draft</button></form><?php endif; ?>
                </div></div>
            <?php elseif ($st === 'issued'): ?>
                <div class="card mb-3" id="vendor-credit-actions"><div class="card-body">
                    <?php if ($may['write'] && $targets !== []): ?>
                        <form method="post" action="/vendor-credits/apply.php" hx-post="/vendor-credits/apply.php" hx-target="#flash" class="mb-2" id="vendor-credit-apply-form"><?= csrf_field() ?><input type="hidden" name="vendor_credit" value="<?= $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                            <label class="form-label fs-12 text-muted" for="vendor-credit-apply-bill">Apply to</label><select name="bill" id="vendor-credit-apply-bill" class="form-select btn-touch mb-2"><?php foreach ($targets as $b): ?><option value="<?= $b['bill_id'] ?>"><?= e((string) $b['number']) ?> — <?= e(fmt_money($b['balance_due'], $currency)) ?> owed</option><?php endforeach; ?></select>
                            <input type="text" inputmode="decimal" name="amount" class="form-control btn-touch mb-2" placeholder="Amount (empty: what fits)" id="vendor-credit-apply-amount"><button type="submit" class="btn btn-primary btn-touch w-100" id="vendor-credit-apply-btn">Apply</button></form>
                    <?php endif; ?>
                    <?php if ($may['override']): ?><form method="post" action="/vendor-credits/void.php" hx-post="/vendor-credits/void.php" hx-target="#flash" hx-confirm="Void this vendor credit? Its entry is reversed."><?= csrf_field() ?><input type="hidden" name="vendor_credit" value="<?= $id ?>">
                        <input type="text" name="reason" class="form-control btn-touch mb-2" placeholder="Why it is voided" maxlength="500" required id="vendor-credit-void-reason"><button type="submit" class="btn btn-light btn-touch w-100" id="vendor-credit-void-btn">Void</button></form><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?= view('shared/attachments.php', ['type' => 'vendor_credit', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'vendor-credit']) ?>
            <?= view('shared/notes.php', ['type' => 'vendor_credit', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'vendor-credit']) ?>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="vendor-credit-applications"><div class="card-header"><h5 class="card-title mb-0">Applied to</h5></div>
                <?php if ($vc['applications'] === []): ?><div class="card-body text-muted" id="vendor-credit-applications-empty">Not applied to a bill.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($vc['applications'] as $a): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center gap-2" id="vendor-credit-application-<?= $a['bill_id'] ?>"><span><?= hx_link(with_back('/bills/' . $a['bill_id'], $here), e((string) $a['bill_number'])) ?></span>
                        <span class="d-flex align-items-center gap-2"><span><?= e(fmt_money($a['amount'], $currency)) ?></span>
                        <?php if ($may['write']): ?><form method="post" action="/vendor-credits/unapply.php" hx-post="/vendor-credits/unapply.php" hx-target="#flash" hx-confirm="Take this credit off the bill?"><?= csrf_field() ?><input type="hidden" name="vendor_credit" value="<?= $id ?>"><input type="hidden" name="bill" value="<?= $a['bill_id'] ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>"><button type="submit" class="btn btn-sm btn-light" id="vendor-credit-application-<?= $a['bill_id'] ?>-unapply-btn">Unapply</button></form><?php endif; ?></span></div>
                <?php endforeach; ?></div><?php endif; ?>
            </div>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'vendor-credit']) ?>
        </div>
    </div>
</div>

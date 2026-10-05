<?php /** One vendor (screen `vendor-view`). Data: v, open, payments, credits, attachments, notes, timeline, may, here, tz, notice */ $id = (int) $v['vendor_id']; ?>
<?= view('shared/header.php', ['id' => 'vendor-view', 'title' => $v['name'], 'crumbs' => [['Home', '/'], ['Vendors', '/vendors/'], [$v['name'], null]], 'back' => back_link() ?? ['/vendors/', 'vendors'],
    'action' => $may['write'] ? hx_link(with_back('/vendors/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="vendor-view-edit-btn"') : '']) ?>
<div class="main-content" id="vendor-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="vendor-view-facts"><div class="card-body">
                <div class="d-flex gap-1 mb-2"><?php if ($v['kind'] !== 'vendor'): ?><span class="badge bg-soft-info text-info" id="vendor-view-kind"><?= e(VENDOR_KINDS[$v['kind']] ?? $v['kind']) ?></span><?php endif; ?><?php if ($v['archived_at'] !== null): ?><span class="badge bg-soft-danger text-danger" id="vendor-view-archived">Archived</span><?php endif; ?></div>
                <div class="mb-2" id="vendor-view-balance"><?php if ($v['balances'] === []): ?><span class="text-muted">Nothing owed</span><?php else: foreach ($v['balances'] as $b): ?><div class="fs-20 fw-bold"><?= e(fmt_money($b['balance'], $b['currency'])) ?> <span class="fs-12 text-muted fw-normal">owed</span></div><?php endforeach; endif; ?></div>
                <dl class="row mb-0 fs-12">
                    <?php if ($v['legal_name']): ?><dt class="col-5 text-muted">Legal name</dt><dd class="col-7"><?= e($v['legal_name']) ?></dd><?php endif; ?>
                    <?php if ($v['email'] !== null): ?><dt class="col-5 text-muted">Email</dt><dd class="col-7" id="vendor-view-email"><?= e($v['email']) ?></dd><?php endif; ?>
                    <?php if ($v['phone']): ?><dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e($v['phone']) ?></dd><?php endif; ?>
                    <?php if ($v['remit_address']): ?><dt class="col-5 text-muted">Remit to</dt><dd class="col-7"><?= nl2br(e($v['remit_address'])) ?></dd><?php endif; ?>
                    <?php if ($v['tax_id']): ?><dt class="col-5 text-muted">Tax id</dt><dd class="col-7"><?= e($v['tax_id']) ?></dd><?php endif; ?>
                    <dt class="col-5 text-muted">Terms</dt><dd class="col-7" id="vendor-view-terms"><?= $v['terms_days'] === null ? 'The settings\'' : (int) $v['terms_days'] . ' days' ?></dd>
                    <dt class="col-5 text-muted">Currency</dt><dd class="col-7"><?= e($v['currency'] ?? 'The base currency') ?></dd>
                    <?php if ($v['pay_method']): ?><dt class="col-5 text-muted">Pays by</dt><dd class="col-7" id="vendor-view-pay"><?= e(PAY_METHODS[$v['pay_method']] ?? $v['pay_method']) ?></dd><?php endif; ?>
                    <?php if ($v['member_id'] !== null): ?><dt class="col-5 text-muted">Member</dt><dd class="col-7">Yes — an employee reimbursed through bills</dd><?php endif; ?>
                    <?php if ($v['notes']): ?><dt class="col-5 text-muted">Notes</dt><dd class="col-7"><?= nl2br(e($v['notes'])) ?></dd><?php endif; ?>
                </dl>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($may['write']): ?><?= hx_link(with_back('/bills/new?vendor=' . $id, $here), 'New bill', 'btn btn-light btn-touch', 'id="vendor-view-bill-btn"') ?><?= hx_link(with_back('/payments/new?vendor=' . $id, $here), 'Prepare a payment', 'btn btn-light btn-touch', 'id="vendor-view-payment-btn"') ?><?php endif; ?>
                </div>
                <?php if ($may['write']): ?>
                    <form method="post" action="/vendors/archive.php" hx-post="/vendors/archive.php" hx-target="#flash" class="mt-3" <?= $v['archived_at'] === null ? 'hx-confirm="Archive ' . e($v['name']) . '?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="vendor" value="<?= $id ?>"><input type="hidden" name="archived" value="<?= $v['archived_at'] === null ? 'yes' : 'no' ?>"><input type="hidden" name="return_to" value="<?= e($v['archived_at'] === null ? '/vendors/' : '/vendors/' . $id) ?>">
                        <button type="submit" class="btn btn-light btn-touch w-100" id="vendor-view-archive-btn"><?= $v['archived_at'] === null ? 'Archive' : 'Restore' ?></button></form>
                <?php endif; ?>
                <?php if ($may['delete']): ?>
                    <form method="post" action="/vendors/delete.php" hx-post="/vendors/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Delete <?= e($v['name']) ?>? Only a vendor with no document at all can be deleted."><?= csrf_field() ?><input type="hidden" name="vendor" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="vendor-view-delete-btn">Delete</button></form>
                <?php endif; ?>
            </div></div>
            <?php if ($may['write']): ?><?= view('customers/partials/contacts.php', ['kind' => 'vendor', 'party' => $id, 'contacts' => $v['contacts'], 'may' => $may, 'here' => $here]) ?><?php endif; ?>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="vendor-view-open"><div class="card-header"><h5 class="card-title mb-0">Open bills</h5></div>
                <?php if ($open === []): ?><div class="card-body text-muted" id="vendor-view-open-empty">Nothing open.</div><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Bill</th><th>Their ref</th><th>Due</th><th class="text-end">Balance</th></tr></thead><tbody>
                    <?php foreach ($open as $b): ?><tr id="vendor-open-<?= $b['bill_id'] ?>"><td><?= hx_link(with_back('/bills/' . $b['bill_id'], $here), e((string) $b['number'])) ?><?= !$b['has_attachment'] ? ' <span class="badge bg-soft-warning text-warning">no document</span>' : '' ?></td><td><?= e((string) $b['vendor_ref']) ?></td><td><?= e(format_date($b['due_date'])) ?><?= $b['overdue'] ? ' <span class="badge bg-danger">' . (int) $b['days_overdue'] . ' days</span>' : '' ?></td><?= fmt_money_cell($b['balance_due'], $b['currency']) ?></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </div>
            <div class="card mb-3" id="vendor-view-payments"><div class="card-header"><h5 class="card-title mb-0">Payments</h5><?= hx_link(with_back('/payments/?vendor=' . $id, $here), 'All', 'fs-12') ?></div>
                <?php if ($payments === []): ?><div class="card-body text-muted">None yet.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($payments as $p): ?><div class="list-group-item d-flex justify-content-between"><span><?= hx_link(with_back('/payments/' . $p['payment_id'], $here), e($p['number'])) ?> <span class="text-muted fs-12"><?= e(format_date($p['payment_date'])) ?> · <?= e($p['status']) ?></span></span><span><?= e(fmt_money($p['amount'], $p['currency'])) ?></span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <div class="card mb-3" id="vendor-view-credits"><div class="card-header"><h5 class="card-title mb-0">Credits</h5><?= hx_link(with_back('/vendor-credits/?vendor=' . $id, $here), 'All', 'fs-12') ?></div>
                <?php if ($credits === []): ?><div class="card-body text-muted">None yet.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($credits as $c): ?><div class="list-group-item d-flex justify-content-between"><span><?= hx_link(with_back('/vendor-credits/' . $c['vendor_credit_id'], $here), e((string) $c['number'])) ?> <span class="text-muted fs-12"><?= e(format_date($c['credit_date'])) ?></span></span><span><?= e(fmt_money($c['total'], $c['currency'])) ?></span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <?= view('shared/attachments.php', ['type' => 'vendor', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'vendor']) ?>
            <?= view('shared/notes.php', ['type' => 'vendor', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'vendor']) ?>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'vendor']) ?>
        </div>
    </div>
</div>

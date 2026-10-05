<?php /** One customer (screen `customer-view`). Data: c, open, receipts, credits, claims, attachments, notes, timeline, may, here, tz, notice */ $id = (int) $c['customer_id']; ?>
<?= view('shared/header.php', ['id' => 'customer-view', 'title' => $c['name'], 'crumbs' => [['Home', '/'], ['Customers', '/customers/'], [$c['name'], null]], 'back' => back_link() ?? ['/customers/', 'customers'],
    'action' => $may['write'] ? hx_link(with_back('/customers/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="customer-view-edit-btn"') : '']) ?>
<div class="main-content" id="customer-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card mb-3" id="customer-view-facts"><div class="card-body">
                <?php if ($c['archived_at'] !== null): ?><span class="badge bg-soft-danger text-danger mb-2" id="customer-view-archived">Archived</span><?php endif; ?>
                <div class="mb-2" id="customer-view-balance"><?php if ($c['balances'] === []): ?><span class="text-muted">Nothing owed</span><?php else: foreach ($c['balances'] as $b): ?><div class="fs-20 fw-bold"><?= e(fmt_money($b['balance'], $b['currency'])) ?> <span class="fs-12 text-muted fw-normal">due</span></div><?php endforeach; endif; ?></div>
                <dl class="row mb-0 fs-12">
                    <?php if ($c['legal_name']): ?><dt class="col-5 text-muted">Legal name</dt><dd class="col-7"><?= e($c['legal_name']) ?></dd><?php endif; ?>
                    <?php if ($c['email'] !== null): ?><dt class="col-5 text-muted">Email</dt><dd class="col-7" id="customer-view-email"><?= e($c['email']) ?></dd><?php endif; ?>
                    <?php if ($c['phone']): ?><dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e($c['phone']) ?></dd><?php endif; ?>
                    <?php if ($c['billing_address']): ?><dt class="col-5 text-muted">Billing</dt><dd class="col-7"><?= nl2br(e($c['billing_address'])) ?></dd><?php endif; ?>
                    <?php if ($c['tax_id']): ?><dt class="col-5 text-muted">Tax id</dt><dd class="col-7"><?= e($c['tax_id']) ?></dd><?php endif; ?>
                    <dt class="col-5 text-muted">Terms</dt><dd class="col-7" id="customer-view-terms"><?= $c['terms_days'] === null ? 'The settings\'' : (int) $c['terms_days'] . ' days' ?></dd>
                    <dt class="col-5 text-muted">Currency</dt><dd class="col-7"><?= e($c['currency'] ?? 'The base currency') ?></dd>
                    <?php if ($c['member_id'] !== null): ?><dt class="col-5 text-muted">Member</dt><dd class="col-7">Yes — a member of this business</dd><?php endif; ?>
                    <?php if ($c['notes']): ?><dt class="col-5 text-muted">Notes</dt><dd class="col-7"><?= nl2br(e($c['notes'])) ?></dd><?php endif; ?>
                </dl>
                <div class="d-flex flex-wrap gap-2 mt-3">
                    <?php if ($may['write']): ?><?= hx_link(with_back('/invoices/new?customer=' . $id, $here), 'New invoice', 'btn btn-light btn-touch', 'id="customer-view-invoice-btn"') ?><?= hx_link(with_back('/receipts/new?customer=' . $id, $here), 'Record a receipt', 'btn btn-light btn-touch', 'id="customer-view-receipt-btn"') ?><?php endif; ?>
                    <?= hx_link(with_back('/customers/' . $id . '/statement', $here), 'Statement', 'btn btn-light btn-touch', 'id="customer-view-statement-btn"') ?>
                </div>
                <?php if ($may['write']): ?>
                    <form method="post" action="/customers/archive.php" hx-post="/customers/archive.php" hx-target="#flash" class="mt-3" <?= $c['archived_at'] === null ? 'hx-confirm="Archive ' . e($c['name']) . '?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="customer" value="<?= $id ?>"><input type="hidden" name="archived" value="<?= $c['archived_at'] === null ? 'yes' : 'no' ?>"><input type="hidden" name="return_to" value="<?= e($c['archived_at'] === null ? '/customers/' : '/customers/' . $id) ?>">
                        <button type="submit" class="btn btn-light btn-touch w-100" id="customer-view-archive-btn"><?= $c['archived_at'] === null ? 'Archive' : 'Restore' ?></button></form>
                <?php endif; ?>
                <?php if ($may['delete']): ?>
                    <form method="post" action="/customers/delete.php" hx-post="/customers/delete.php" hx-target="#flash" class="mt-2" hx-confirm="Delete <?= e($c['name']) ?>? Only a customer with no document at all can be deleted."><?= csrf_field() ?><input type="hidden" name="customer" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100 text-danger" id="customer-view-delete-btn">Delete</button></form>
                <?php endif; ?>
            </div></div>
            <?php if ($may['write']): ?><?= view('customers/partials/contacts.php', ['kind' => 'customer', 'party' => $id, 'contacts' => $c['contacts'], 'may' => $may, 'here' => $here]) ?><?php endif; ?>
        </div>
        <div class="col-lg-7">
            <div class="card mb-3" id="customer-view-open"><div class="card-header"><h5 class="card-title mb-0">Open invoices</h5></div>
                <?php if ($open === []): ?><div class="card-body text-muted" id="customer-view-open-empty">Nothing open.</div><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Invoice</th><th>Due</th><th>Status</th><th class="text-end">Balance</th></tr></thead><tbody>
                    <?php foreach ($open as $i): ?><tr id="customer-open-<?= $i['invoice_id'] ?>"><td><?= hx_link(with_back('/invoices/' . $i['invoice_id'], $here), e((string) $i['number'])) ?></td><td><?= e(format_date($i['due_date'])) ?><?= $i['overdue'] ? ' <span class="badge bg-danger">' . (int) $i['days_overdue'] . ' days</span>' : '' ?></td><td><?= e($i['status']) ?></td><?= fmt_money_cell($i['balance_due'], $i['currency']) ?></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
            </div>
            <?php if ($claims !== []): ?>
            <div class="card mb-3" id="customer-view-claims"><div class="card-header"><h5 class="card-title mb-0">Said paid</h5></div>
                <div class="list-group list-group-flush"><?php foreach ($claims as $k): ?><div class="list-group-item fs-12"><?= hx_link(with_back('/invoices/' . (int) $k['invoice_id'], $here), e((string) $k['invoice_number'])) ?> — <?= e(fmt_money($k['amount'], 'USD', false)) ?> by <?= e($k['method']) ?> on <?= e(format_date($k['claimed_on'])) ?></div><?php endforeach; ?></div>
            </div>
            <?php endif; ?>
            <div class="card mb-3" id="customer-view-receipts"><div class="card-header"><h5 class="card-title mb-0">Receipts</h5><?= hx_link(with_back('/receipts/?customer=' . $id, $here), 'All', 'fs-12') ?></div>
                <?php if ($receipts === []): ?><div class="card-body text-muted">None yet.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($receipts as $r): ?><div class="list-group-item d-flex justify-content-between"><span><?= hx_link(with_back('/receipts/' . $r['receipt_id'], $here), e($r['number'])) ?> <span class="text-muted fs-12"><?= e(format_date($r['received_on'])) ?> · <?= e($r['method']) ?></span></span><span><?= e(fmt_money($r['amount'], $r['currency'])) ?></span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <div class="card mb-3" id="customer-view-credits"><div class="card-header"><h5 class="card-title mb-0">Credits</h5><?= hx_link(with_back('/credit-notes/?customer=' . $id, $here), 'All', 'fs-12') ?></div>
                <?php if ($credits === []): ?><div class="card-body text-muted">None yet.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($credits as $r): ?><div class="list-group-item d-flex justify-content-between"><span><?= hx_link(with_back('/credit-notes/' . $r['credit_note_id'], $here), e((string) $r['number'])) ?> <span class="text-muted fs-12"><?= e(format_date($r['issue_date'])) ?></span></span><span><?= e(fmt_money($r['total'], $r['currency'])) ?></span></div><?php endforeach; ?></div><?php endif; ?>
            </div>
            <?= view('shared/attachments.php', ['type' => 'customer', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'customer']) ?>
            <?= view('shared/notes.php', ['type' => 'customer', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'customer']) ?>
            <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'customer']) ?>
        </div>
    </div>
</div>

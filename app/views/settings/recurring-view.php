<?php /** One recurring template (screen `recurring-view`). Data: t, timeline, may, pdo, here, tz, notice */
$id = $t['recurring_template_id']; $tpl = $t['template']; $kind = $t['kind']; ?>
<?= view('shared/header.php', ['id' => 'recurring-view', 'title' => $t['name'], 'crumbs' => [['Home', '/'], ['Settings', '/settings/'], ['Recurring', '/settings/recurring/'], [$t['name'], null]], 'back' => back_link() ?? ['/settings/recurring/', 'the recurring templates'], 'action' => hx_link('/settings/recurring/' . $id . '/edit', 'Change', 'btn btn-light btn-touch', 'id="recurring-edit-btn"')]) ?>
<div class="main-content" id="recurring-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($t['last_failed'] !== null && ($t['runs'][0]['status'] ?? '') === 'failed'): ?><div class="alert alert-danger" id="recurring-view-failed">The last run failed: <?= e($t['last_failed']) ?></div><?php endif; ?>
    <div class="card mb-3"><div class="card-body" id="recurring-view-summary">
        <div class="d-flex justify-content-between"><div><div class="fw-bold"><?= e(TEMPLATE_KINDS[$kind]) ?> · <?= e(cadence_words($t['cadence'], $t['every_n'])) ?></div>
            <div class="fs-12 text-muted">Next <?= e(format_date($t['next_date'])) ?><?= $t['end_date'] !== null ? ' · ends ' . e(format_date($t['end_date'])) : '' ?> · <?= $t['run_count'] ?> run<?= $t['run_count'] === 1 ? '' : 's' ?></div></div>
            <span class="badge <?= $t['active'] ? 'bg-soft-success text-success' : 'bg-soft-secondary text-secondary' ?>"><?= $t['active'] ? 'On' : 'Off' ?></span></div>
        <?php if (!empty($tpl['memo'])): ?><div class="mt-2"><?= e($tpl['memo']) ?></div><?php endif; ?>
        <?php if ($kind === 'bill' && !empty($tpl['vendor'])): ?><div class="fs-12 mt-1">Vendor <?= e((string) $pdo->query('SELECT name FROM mcp_vendors WHERE vendor_id = ' . (int) $tpl['vendor'])->fetchColumn()) ?><?= !empty($tpl['vendor_ref_pattern']) ? ' · reference ' . e($tpl['vendor_ref_pattern']) : '' ?></div><?php endif; ?>
        <?php if ($kind === 'invoice' && !empty($tpl['customer'])): ?><div class="fs-12 mt-1">Customer <?= e((string) $pdo->query('SELECT name FROM mcp_customers WHERE customer_id = ' . (int) $tpl['customer'])->fetchColumn()) ?><?= !empty($tpl['reference_pattern']) ? ' · reference ' . e($tpl['reference_pattern']) : '' ?></div><?php endif; ?>
    </div></div>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">What it drafts</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0" id="recurring-view-lines">
        <?php foreach ($tpl['lines'] ?? [] as $i => $l): $acc = $l['account'] ?? $l['expense_account'] ?? $l['income_account'] ?? null; ?>
            <tr><td class="ps-3"><?= $i + 1 ?></td><td><?= e((string) ($l['description'] ?? '')) ?><div class="fs-12 text-muted"><?= $acc !== null ? e((string) $pdo->query('SELECT code || \' \' || name FROM mcp_accounts WHERE account_id = ' . (int) $acc)->fetchColumn()) : '' ?></div></td>
            <td class="text-end pe-3"><?= $kind === 'journal' ? ((float) $l['debit'] > 0 ? 'Dr ' . e(fmt_money($l['debit'], '')) : 'Cr ' . e(fmt_money($l['credit'], ''))) : e((string) $l['quantity']) . ' × ' . e((string) $l['unit_price']) ?></td></tr>
        <?php endforeach; ?></table></div></div></div>
    <?php if ($may['run']): ?><form method="post" action="/settings/recurring/run.php" hx-post="/settings/recurring/run.php" hx-target="#flash" class="card mb-3"><div class="card-body"><?= csrf_field() ?><input type="hidden" name="template" value="<?= $id ?>">
        <label class="form-label fs-12 text-muted" for="recurring-run-date">Draft one now, dated</label><div class="row g-2"><div class="col-7"><input type="date" name="run_date" id="recurring-run-date" class="form-control btn-touch" value="<?= e($t['next_date']) ?>"></div><div class="col-5"><button class="btn btn-primary btn-touch w-100" id="recurring-run-btn">Run now</button></div></div>
        <div class="fs-12 text-muted mt-1">It drafts; a person posts, approves or issues. The schedule moves to the next date.</div></div></form><?php endif; ?>
    <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Runs</h5></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0" id="recurring-view-runs">
        <?php if ($t['runs'] === []): ?><tr><td class="text-muted p-3">Not run yet.</td></tr><?php endif; ?>
        <?php foreach ($t['runs'] as $r): ?><tr><td class="ps-3"><?= e(format_date($r['run_date'])) ?></td><td><span class="badge bg-soft-<?= RUN_STATUS_BADGE_REC[$r['status']] ?? 'secondary' ?> text-<?= RUN_STATUS_BADGE_REC[$r['status']] ?? 'secondary' ?>"><?= e($r['status']) ?></span></td>
            <td class="pe-3"><?= $r['record_url'] !== null ? hx_link(with_back($r['record_url'], $here), e((string) $r['record_label'])) : e((string) $r['detail']) ?></td></tr><?php endforeach; ?></table></div></div></div>
    <form method="post" action="/settings/recurring/delete.php" hx-post="/settings/recurring/delete.php" hx-target="#flash" hx-confirm="Delete <?= e($t['name']) ?>? Its runs go with it; the documents it drafted stay."><?= csrf_field() ?><input type="hidden" name="template" value="<?= $id ?>"><button class="btn btn-light btn-touch w-100 text-danger" id="recurring-delete-btn">Delete the template</button></form>
</div>

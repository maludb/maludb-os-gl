<?php /** One month as a card. Data: s, may, here */
if (!($s['pulled'] ?? true)): $pid = str_replace('-', '', $s['period']); ?>
<div class="card h-100 opacity-75" id="ai-statement-card-<?= e($pid) ?>-notpulled"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><?= e($s['period']) ?></div><span class="badge bg-<?= $s['kernel_status'] === 'closed' ? 'secondary' : 'warning' ?>"><?= $s['kernel_status'] === 'closed' ? 'closed' : 'open in the OS' ?></span></div>
    <div class="fs-12 text-muted mt-1">The OS lists this month<?= ($s['statement_lines'] ?? 0) > 0 ? ' (' . (int) $s['statement_lines'] . ' lines)' : '' ?>; the ledger has not pulled it yet.</div>
    <?php if ($may['pull']): ?><form method="post" action="/ap/ai/pull.php" hx-post="/ap/ai/pull.php" hx-target="#flash" class="mt-2"><?= csrf_field() ?><input type="hidden" name="period" value="<?= e($s['period']) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="ai-statement-card-<?= e($pid) ?>-pull-btn">Pull <?= e($s['period']) ?></button></form><?php endif; ?>
</div></div>
<?php return; endif; $id = (int) $s['ai_statement_id']; $url = with_back('/ap/ai/' . $id, $here); ?>
<div class="card h-100" id="ai-statement-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><?= hx_link($url, e($s['period']), 'text-dark') ?></div>
        <span class="d-flex gap-1"><span class="badge bg-<?= $s['kernel_status'] === 'closed' ? 'secondary' : 'warning' ?>" id="ai-statement-card-<?= $id ?>-kernel"><?= $s['kernel_status'] === 'closed' ? 'closed' : 'open in the OS' ?></span><span class="badge bg-<?= AI_STATUS_BADGE[$s['status']] ?>" id="ai-statement-card-<?= $id ?>-status"><?= e($s['status']) ?></span></span></div>
    <div class="fs-12 text-muted">Pulled <?= e(format_date(substr((string) $s['pulled_at'], 0, 10))) ?><?= $s['late_calls'] > 0 ? ' · <span class="text-warning">' . (int) $s['late_calls'] . ' late call' . ($s['late_calls'] === 1 ? '' : 's') . '</span>' : '' ?></div>
    <div class="mt-2" id="ai-statement-card-<?= $id ?>-totals"><?php foreach ($s['totals'] as $cur => $amt): ?><div><span class="fw-bold"><?= e(fmt_money((string) $amt, (string) $cur)) ?></span></div><?php endforeach; ?><?php if ($s['totals'] === []): ?><span class="text-muted fs-12">No usage</span><?php endif; ?></div>
    <?php if ($s['bills'] !== []): ?><div class="fs-12 mt-1" id="ai-statement-card-<?= $id ?>-bills"><?php foreach ($s['bills'] as $b): ?><div><?= hx_link(with_back('/bills/' . $b['bill_id'], $here), e($b['number'])) ?> <span class="text-muted"><?= e(ai_provider_label($b['provider'])) ?></span> <span class="badge bg-<?= BILL_STATUS_BADGE[$b['status']] ?>"><?= e(BILL_STATUSES[$b['status']] ?? $b['status']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
    <div class="mt-2"><?= hx_link($url, 'Open', 'btn btn-light btn-touch w-100', 'id="ai-statement-card-' . $id . '-open-btn"') ?></div>
</div></div>

<?php /** One fiscal year as a card with its periods as chips. Data: y, may, here, tz */ $id = (int) $y['fiscal_year_id']; ?>
<div class="card mb-3" id="fiscal-year-card-<?= $id ?>"><div class="card-body p-3">
    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <div><div class="fw-bold" id="fiscal-year-card-<?= $id ?>-name"><?= e($y['name']) ?></div><div class="fs-12 text-muted"><?= e(format_date($y['start_date'])) ?> – <?= e(format_date($y['end_date'])) ?></div></div>
        <div id="fiscal-year-card-<?= $id ?>-status">
            <?php if ($y['status'] === 'closed'): ?><span class="badge bg-dark">Closed</span><span class="fs-12 text-muted ms-1">by <?= e($y['closed_by_name'] ?? '?') ?>, <?= e(format_ts($y['closed_at'], $tz, 'M j, Y')) ?><?= $y['closing_entry_id'] !== null ? ' · ' . hx_link(with_back('/journal/' . (int) $y['closing_entry_id'], $here), e((string) ($y['closing_entry_number'] ?? 'closing entry'))) : '' ?></span>
            <?php else: ?><span class="badge bg-success">Open</span><span class="fs-12 text-muted ms-1"><?= (int) $y['open_periods'] ?> of <?= count($y['periods']) ?> periods open</span><?php endif; ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-3" id="fiscal-year-card-<?= $id ?>-periods"><?php foreach ($y['periods'] as $p): ?><?= view('periods/partials/period-chip.php', ['p' => $p, 'here' => $here]) ?><?php endforeach; ?></div>
</div></div>

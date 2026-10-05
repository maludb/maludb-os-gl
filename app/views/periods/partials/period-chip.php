<?php /** One period as a chip: open (success), closed (dark), reopened N× (warning when reopened and open); posted and draft counts under it. Data: p, here */
$pid = (int) $p['fiscal_period_id']; $open = $p['status'] === 'open'; $re = (int) $p['reopen_count'];
$kind = !$open ? 'dark' : ($re > 0 ? 'warning' : 'success'); $word = !$open ? 'closed' : ($re > 0 ? 'reopened ' . $re . '×' : 'open'); ?>
<a href="<?= e(with_back('/periods/' . $pid, $here)) ?>" hx-get="<?= e(with_back('/periods/' . $pid, $here)) ?>" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="<?= e('/periods/' . $pid) ?>" class="text-decoration-none border rounded px-2 py-1 text-center btn-touch d-inline-flex flex-column justify-content-center" id="period-chip-<?= $pid ?>" style="min-width:5.5rem" data-status="<?= e($word) ?>">
    <span class="fw-semibold text-dark"><?= e($p['name']) ?></span>
    <span class="badge bg-<?= $kind ?> mt-1" id="period-chip-<?= $pid ?>-status"><?= e($word) ?></span>
    <span class="fs-12 text-muted mt-1" id="period-chip-<?= $pid ?>-counts"><?= (int) $p['posted_entries'] ?> posted<?= (int) $p['draft_entries'] > 0 ? ' · ' . (int) $p['draft_entries'] . ' draft' : '' ?></span>
</a>

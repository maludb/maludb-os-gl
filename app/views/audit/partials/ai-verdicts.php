<?php /** The AI statement's verdicts (audit). Data: a, tz, here */
$badge = ['agrees' => 'success', 'differs' => 'danger', 'imported' => 'warning', 'pulled' => 'secondary']; ?>
<div class="card mb-3" id="audit-ai"><div class="card-header"><h5 class="card-title mb-0">The AI statement</h5></div><div class="card-body">
    <?php if ($a['ai'] === []): ?><div class="text-muted fs-12" id="audit-ai-empty">No AI statement month falls in this range.</div><?php endif; ?>
    <?php foreach ($a['ai'] as $s): $id = $s['ai_statement_id']; ?>
        <div class="border rounded p-2 mb-2" id="audit-ai-<?= $id ?>">
            <div class="d-flex justify-content-between"><div class="fw-semibold"><?= hx_link(with_back('/ap/ai/' . $id, $here), e($s['period']), 'text-dark') ?></div><span class="badge bg-soft-<?= $badge[$s['status']] ?? 'secondary' ?> text-<?= $badge[$s['status']] ?? 'secondary' ?>" id="audit-ai-<?= $id ?>-verdict"><?= e($s['status']) ?></span></div>
            <div class="fs-12 text-muted"><?= $s['checked_at'] !== null ? 'Checked ' . e(format_ts($s['checked_at'], $tz, 'M j, g:i A')) : 'Not checked yet' ?> · <?= (int) $s['bill_count'] ?> bill<?= (int) $s['bill_count'] === 1 ? '' : 's' ?></div>
            <?php foreach ($s['differences'] as $i => $d): ?><div class="fs-12 text-danger" id="audit-ai-<?= $id ?>-diff-<?= $i + 1 ?>"><?= e($d) ?></div><?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div></div>

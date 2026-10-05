<?php /** The close checklist (read-only lines, ✓ / ✗ / –). Data: checklist, here */ ?>
<div class="card" id="period-view-checklist"><div class="card-header"><h5 class="card-title mb-0">Before closing</h5></div>
    <div class="list-group list-group-flush">
        <?php foreach ($checklist as $c): ?>
            <div class="list-group-item d-flex gap-2 align-items-start" id="period-check-<?= e($c['key']) ?>" data-ok="<?= $c['ok'] === null ? 'na' : ($c['ok'] ? 'yes' : 'no') ?>">
                <span class="<?= $c['ok'] === null ? 'text-muted' : ($c['ok'] ? 'text-success' : 'text-danger') ?> fw-bold"><?= $c['ok'] === null ? '–' : ($c['ok'] ? '✓' : '✗') ?></span>
                <div class="min-w-0"><div><?= e($c['label']) ?></div><div class="fs-12 text-muted"><?= hx_link(with_back($c['url'], $here), e($c['detail'])) ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

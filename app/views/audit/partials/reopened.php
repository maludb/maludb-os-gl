<?php /** Reopened periods (audit). Data: a, tz, here */ ?>
<div class="card mb-3" id="audit-reopened"><div class="card-header"><h5 class="card-title mb-0">Reopened periods</h5></div><div class="card-body">
    <?php if ($a['reopened'] === []): ?><div class="text-muted fs-12" id="audit-reopened-empty">No period was reopened in this range.</div><?php endif; ?>
    <?php foreach ($a['reopened'] as $p): $id = $p['fiscal_period_id']; ?>
        <div class="border rounded p-2 mb-2" id="audit-reopened-<?= $id ?>">
            <div class="fw-semibold"><?= hx_link(with_back('/periods/' . $id, $here), e($p['name']), 'text-dark') ?> <span class="fs-12 text-muted">· reopened <?= (int) $p['reopen_count'] ?> time<?= (int) $p['reopen_count'] === 1 ? '' : 's' ?> · now <?= e($p['status']) ?></span></div>
            <?php foreach ($p['reopenings'] as $o): ?><div class="fs-12" id="audit-reopened-<?= $id ?>-reopening"><?= e((string) $o['actor_name']) ?> reopened it on <?= e(format_ts($o['occurred_at'], $tz, 'M j, Y g:i A')) ?> — “<?= e((string) $o['reason']) ?>”</div><?php endforeach; ?>
            <div class="fs-12 mt-1 text-muted">Posted into it after the reopening:</div>
            <?php if ($p['posts'] === []): ?><div class="fs-12" id="audit-reopened-<?= $id ?>-none">Nothing.</div><?php endif; ?>
            <?php foreach ($p['posts'] as $x): ?><div class="fs-12" id="audit-reopened-<?= $id ?>-post-<?= (int) $x['journal_entry_id'] ?>"><?= hx_link(with_back('/journal/' . (int) $x['journal_entry_id'], $here), e((string) $x['number'])) ?> · <?= e(fmt_money((string) $x['amount'], (string) $x['currency'])) ?> · dated <?= e(format_date((string) $x['entry_date'])) ?> · posted by <?= e((string) $x['actor_name']) ?>, <?= e(format_ts($x['occurred_at'], $tz, 'M j, g:i A')) ?></div><?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div></div>

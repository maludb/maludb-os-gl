<?php /** Reversals and voids (audit). Data: a, tz, here */ ?>
<div class="card mb-3" id="audit-reversals"><div class="card-header"><h5 class="card-title mb-0">Reversals and voids</h5></div><div class="card-body">
    <div class="fs-12 fw-semibold mb-1">Entries reversed</div>
    <?php if ($a['reversals'] === []): ?><div class="text-muted fs-12" id="audit-reversals-empty">No entry was reversed in this range.</div><?php endif; ?>
    <?php foreach ($a['reversals'] as $x): $id = (int) $x['journal_entry_id']; ?>
        <div class="border rounded p-2 mb-2 fs-12" id="audit-reversal-<?= $id ?>"><?= hx_link(with_back('/journal/' . $id, $here), e((string) $x['number']), 'fw-semibold') ?> · <?= e(fmt_money((string) $x['amount'], (string) $x['currency'])) ?> · dated <?= e(format_date((string) $x['entry_date'])) ?>
            <div>Reversed by <?= $x['reversal_id'] !== null ? hx_link(with_back('/journal/' . (int) $x['reversal_id'], $here), e((string) $x['reversal_number'])) : '' ?><?= $x['reversed_by_name'] !== null ? ', posted by ' . e((string) $x['reversed_by_name']) : '' ?> — “<?= e((string) $x['reversal_reason']) ?>”</div></div>
    <?php endforeach; ?>
    <div class="fs-12 fw-semibold mb-1 mt-3">Drafts voided</div>
    <?php if ($a['voids'] === []): ?><div class="text-muted fs-12" id="audit-voids-empty">No draft was voided in this range.</div><?php endif; ?>
    <?php foreach ($a['voids'] as $v): $id = (int) $v['journal_entry_id']; ?>
        <div class="border rounded p-2 mb-2 fs-12" id="audit-void-<?= $id ?>"><?= hx_link(with_back('/journal/' . $id, $here), e((string) ($v['number'] ?? 'Draft #' . $id)), 'fw-semibold') ?> · <?= e(fmt_money((string) $v['amount'], (string) $v['currency'])) ?> · voided by <?= e((string) $v['voided_by_name']) ?>, <?= e(format_ts($v['voided_at'], $tz, 'M j, g:i A')) ?> — “<?= e((string) $v['void_reason']) ?>”</div>
    <?php endforeach; ?>
</div></div>

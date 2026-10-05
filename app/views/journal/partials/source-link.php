<?php /** The source document and the reversal links of an entry. Data: e, here */ ?>
<div class="fs-12" id="entry-source">
    <?php if ($e['document'] !== null): ?><div>From <?= e(record_words((string) $e['document']['record_type'])) ?> <?= $e['document']['url'] !== null && $e['document']['label'] !== null ? hx_link(with_back($e['document']['url'], $here), e($e['document']['label']), '', 'id="entry-source-link"') : '<span id="entry-source-label">' . e($e['document']['label'] ?? ('#' . $e['document']['record_id'])) . '</span>' ?></div><?php endif; ?>
    <?php if ($e['reversal_of'] !== null): ?><div>Reverses <?= hx_link(with_back('/journal/' . (int) $e['reversal_of'], $here), e((string) $e['reversal_of_number']), '', 'id="entry-reverses-link"') ?><?= $e['reversal_reason'] !== null ? ' — “' . e($e['reversal_reason']) . '”' : '' ?></div><?php endif; ?>
    <?php if ($e['reversed_by'] !== null): ?><div>Reversed by <?= hx_link(with_back('/journal/' . (int) $e['reversed_by'], $here), e((string) $e['reversed_by_number']), '', 'id="entry-reversed-by-link"') ?></div><?php endif; ?>
</div>

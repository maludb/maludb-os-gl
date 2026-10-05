<?php /** The five groups of account cards (swapped by the filters). Data: groups, filters, currency, may, here */
$any = false; foreach ($groups as $g) { if ($g['rows'] !== []) { $any = true; } } ?>
<div id="accounts-results">
    <?php if (!$any): ?><div class="card"><div class="card-body text-center text-muted py-4" id="accounts-empty">No account matches.</div></div><?php endif; ?>
    <?php foreach ($groups as $t => $g): if ($g['rows'] === []) { continue; } ?>
        <div class="mb-3" id="accounts-group-<?= e($t) ?>">
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="fs-12 text-uppercase text-muted mb-0"><?= e($g['label']) ?></h6>
                <?php if ($may['manage']): ?><?= hx_link('/accounts/new?type=' . $t, '<i class="feather-plus me-1"></i>Add', 'btn btn-sm btn-light', 'id="accounts-group-' . e($t) . '-add-btn"') ?><?php endif; ?></div>
            <div class="row g-2">
                <?php foreach ($g['rows'] as $a): ?><div class="col-12 col-md-6 col-xl-4"><?= view('accounts/partials/account-card.php', ['a' => $a, 'currency' => $currency, 'may' => $may, 'here' => $here]) ?></div><?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

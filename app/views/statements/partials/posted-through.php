<?php /** The posted-through line every statement carries, and the mixed-currency note. Data: pt, currency, mixed */ ?>
<div class="alert alert-light border mb-3 fs-12" id="posted-through"><i class="feather-clock me-1"></i><span id="posted-through-text"><?= e($pt['sentence']) ?></span></div>
<?php if ($mixed !== []): ?>
<div class="alert alert-warning mb-3 fs-12" id="statement-mixed-currency">The books hold entries in <?= e(implode(', ', $mixed)) ?> as well as <?= e($currency) ?>. These figures add every posted entry as it stands — nothing is converted, and the currencies are not yet shown apart.</div>
<?php endif; ?>

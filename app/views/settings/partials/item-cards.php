<?php /** The item cards (swapped by the filters). Data: rows, currency, here */ ?>
<div class="row g-3" id="items-results">
    <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="items-empty">No item yet — add what you sell and what you buy.</div></div></div><?php endif; ?>
    <?php foreach ($rows as $i): ?><div class="col-12 col-md-6 col-xl-4"><?= view('settings/partials/item-card.php', ['i' => $i, 'currency' => $currency, 'here' => $here]) ?></div><?php endforeach; ?>
</div>

<?php /** The customer cards (swapped by the filter). Data: rows, may, here */ ?>
<div class="row g-3" id="customers-results">
    <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="customers-empty">No customer yet — add the first to start invoicing.</div></div></div><?php endif; ?>
    <?php foreach ($rows as $c): ?><div class="col-12 col-md-6 col-xl-4"><?= view('customers/partials/customer-card.php', ['c' => $c, 'may' => $may, 'here' => $here]) ?></div><?php endforeach; ?>
</div>

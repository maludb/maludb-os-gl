<?php /** The vendor cards (swapped by the filter). Data: rows, may, here */ ?>
<div class="row g-3" id="vendors-results">
    <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="vendors-empty">No vendor yet — add the first to start entering bills.</div></div></div><?php endif; ?>
    <?php foreach ($rows as $v): ?><div class="col-12 col-md-6 col-xl-4"><?= view('vendors/partials/vendor-card.php', ['v' => $v, 'may' => $may, 'here' => $here]) ?></div><?php endforeach; ?>
</div>

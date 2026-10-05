<?php /** The month cards (swapped by aiStatementChanged). Data: rows, kernelError, may, here */ ?>
<div id="ai-statements-list" hx-get="/ap/ai/" hx-trigger="aiStatementChanged from:body" hx-target="#ai-statements-list" hx-swap="outerHTML">
    <?php if ($kernelError !== null): ?><div class="alert alert-warning fs-12" id="ai-statements-kernel-error"><i class="feather-alert-triangle me-1"></i><?= e($kernelError) ?> The months below are what the ledger already holds.</div><?php endif; ?>
    <div class="row g-3" id="ai-statements-cards">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="ai-statements-empty">No month yet. <?= $may['pull'] ? 'Press Pull now to ask the OS for its statements.' : 'The bookkeeper pulls the OS\'s statements.' ?></div></div></div><?php endif; ?>
        <?php foreach ($rows as $s): ?><div class="col-12 col-md-6 col-xl-4"><?= view('ai/partials/statement-card.php', ['s' => $s, 'may' => $may, 'here' => $here]) ?></div><?php endforeach; ?>
    </div>
</div>

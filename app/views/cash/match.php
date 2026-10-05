<?php /** The match screen (`bank-match`). Data: bank, banks, cards, more, filters, auto, may, pdo, departments, sites, here, notice */ $id = $bank['bank_account_id'] ?? null; ?>
<?= view('shared/header.php', ['id' => 'bank-match', 'title' => 'Match the bank', 'crumbs' => [['Home', '/'], ['Bank accounts', '/cash/'], ['Match', null]], 'back' => back_link() ?? ['/cash/', 'bank accounts'],
    'action' => $bank !== null ? hx_link(with_back('/cash/import?bank_account=' . (int) $id, $here), '<i class="feather-upload me-1"></i>Import', 'btn btn-light btn-touch', 'id="bank-match-import-btn"') : '']) ?>
<div class="main-content" id="bank-match-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
<?php if ($bank === null): ?>
    <div class="card"><div class="card-body text-center text-muted py-4" id="bank-match-nobank">No bank account yet. <?= hx_link('/settings/banks/new', 'Add one') ?>.</div></div>
<?php else: ?>
    <form method="get" action="/cash/match" hx-get="/cash/match" hx-target="#page-content" hx-push-url="true" id="bank-match-pick" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-12 col-md-6"><label class="form-label fs-12 text-muted" for="bank-match-bank">Bank account</label><select name="bank_account" id="bank-match-bank" class="form-select btn-touch"><?php foreach ($banks as $b): ?><option value="<?= $b['bank_account_id'] ?>" <?= $b['bank_account_id'] === $id ? 'selected' : '' ?>><?= e($b['name']) ?> (<?= e($b['currency']) ?>)</option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="bank-match-from">From</label><input type="date" name="from" id="bank-match-from" class="form-control btn-touch" value="<?= e($filters['from']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="bank-match-to">To</label><input type="date" name="to" id="bank-match-to" class="form-control btn-touch" value="<?= e($filters['to']) ?>"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-light btn-touch w-100" id="bank-match-pick-btn">Show</button></div>
    </div></form>
    <?= view('cash/partials/bank-figures.php', ['bank' => $bank, 'unmatched' => $bank['unmatched_lines']]) ?>
    <?php $sure = count(array_filter($cards, static fn (array $c): bool => count($c['candidates']) === 1)); if ($sure > 0): ?>
        <div class="mb-3"><button type="button" class="btn btn-primary btn-touch w-100" id="bank-match-auto-btn"><i class="feather-zap me-1"></i>Match the <?= $sure ?> sure one<?= $sure === 1 ? '' : 's' ?> (exactly one candidate each)</button><div class="fs-12 text-muted mt-1">Each is its own click, logged on its own. Lines with two candidates or none stay for you.</div></div>
    <?php endif; ?>
    <div id="bank-lines">
        <?php if ($cards === []): ?><div class="card"><div class="card-body text-center text-muted py-4" id="bank-match-empty"><i class="feather-check-circle me-1"></i>Nothing to match in <?= e($bank['name']) ?>. <?= hx_link(with_back('/cash/reconcile?bank_account=' . (int) $id, $here), 'Reconcile it', '', 'id="bank-match-reconcile-link"') ?>.</div></div><?php endif; ?>
        <?php foreach ($cards as $c): ?><?= view('cash/partials/line-card.php', ['line' => $c['line'], 'candidates' => $c['candidates'], 'may' => $may, 'pdo' => $pdo, 'departments' => $departments, 'sites' => $sites, 'here' => $here]) ?><?php endforeach; ?>
        <?php if ($more > 0): ?><div class="fs-12 text-muted" id="bank-match-more"><?= (int) $more ?> more line<?= $more === 1 ? '' : 's' ?> after these; match these and reload.</div><?php endif; ?>
    </div>
    <script>(function () {
        var btn = document.getElementById('bank-match-auto-btn');
        function run() { var forms = Array.prototype.slice.call(document.querySelectorAll('.bank-candidate[data-sure="1"]')); forms.forEach(function (f, i) { setTimeout(function () { if (document.body.contains(f)) { f.requestSubmit(); } }, i * 350); }); }
        if (btn) { btn.addEventListener('click', function () { btn.disabled = true; run(); }); <?= $auto ? 'run();' : '' ?> }
    })();</script>
<?php endif; ?>
</div>

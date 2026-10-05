<?php /** The audit page (screen `audit`). Data: a, tz, here, currency */
$r = $a['range']; ?>
<?= view('shared/header.php', ['id' => 'audit', 'title' => 'Audit', 'crumbs' => [['Home', '/'], ['Audit', null]]]) ?>
<div class="main-content" id="audit-content">
    <form method="get" action="/audit" hx-get="/audit" hx-target="#page-content" hx-push-url="true" id="audit-range" class="card mb-3"><div class="card-body p-3 row g-2 align-items-end">
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="audit-from">From</label><input type="date" name="from" id="audit-from" class="form-control btn-touch" value="<?= e($r['from']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="audit-to">To</label><input type="date" name="to" id="audit-to" class="form-control btn-touch" value="<?= e($r['to']) ?>"></div>
        <div class="col-12 col-md-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="audit-apply-btn">Apply</button></div>
    </div></form>
    <?= view('audit/partials/reopened.php', ['a' => $a, 'tz' => $tz, 'here' => $here]) ?>
    <?= view('audit/partials/reversals.php', ['a' => $a, 'tz' => $tz, 'here' => $here]) ?>
    <?= view('audit/partials/ai-verdicts.php', ['a' => $a, 'tz' => $tz, 'here' => $here]) ?>
    <?= view('audit/partials/tie-out.php', ['a' => $a, 'tz' => $tz, 'here' => $here, 'currency' => $currency]) ?>
    <div class="card mb-3" id="audit-closed"><div class="card-header"><h5 class="card-title mb-0">Posted into a closed period</h5></div><div class="card-body fs-12">
        <span id="audit-closed-count" class="fw-semibold"><?= (int) $a['posted_after_close'] ?></span> entries posted after their period closed. A closed period takes no posting: the database refuses it, and a refused attempt is not a post and is not logged as one. What can happen is a reopening — listed above with who, why and what was posted into it.</div></div>
</div>

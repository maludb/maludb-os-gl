<?php /** A new manual entry (head + first lines) or a draft's head (screens `journal-entry-add`, `journal-entry-edit`). Data: cur, prefill, date, reverseOf, pdo, departments, sites, here */
$id = $cur['journal_entry_id'] ?? null; $screen = $cur === null ? 'journal-entry-add' : 'journal-entry-edit';
$back = $cur === null ? ['/journal/', 'the journal'] : ['/journal/' . (int) $id, 'the entry']; $rows = $prefill['lines'] ?? [];
while (count($rows) < 4) { $rows[] = ['account' => null, 'description' => null, 'debit' => null, 'credit' => null]; } ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'New entry' : 'Change the draft', 'crumbs' => [['Home', '/'], ['Journal', '/journal/'], [$cur === null ? 'New' : ($cur['number'] ?? 'Draft #' . $id), $cur === null ? null : '/journal/' . (int) $id], $cur === null ? ['', null] : ['Change', null]], 'back' => back_link() ?? $back]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/journal/save.php" hx-post="/journal/save.php" hx-target="#flash" id="entry-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="entry" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">The entry</h5></div><div class="card-body">
            <div class="row g-2 mb-3">
                <div class="col-5 col-md-3"><label class="form-label fs-12 text-muted" for="entry-form-field-date">Date</label><input type="date" name="entry_date" id="entry-form-field-date" class="form-control btn-touch" required value="<?= e($date) ?>"></div>
                <div class="col-7 col-md-9"><label class="form-label fs-12 text-muted" for="entry-form-field-memo">Memo</label><input type="text" name="memo" id="entry-form-field-memo" class="form-control btn-touch" maxlength="300" value="<?= e($cur['memo'] ?? $prefill['memo'] ?? '') ?>" placeholder="April hosting accrual"></div>
            </div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label fs-12 text-muted" for="entry-form-field-department">Department</label><select name="department" id="entry-form-field-department" class="form-select btn-touch"><option value="">None</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= ($cur['department_id'] ?? $prefill['department'] ?? null) === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-6"><label class="form-label fs-12 text-muted" for="entry-form-field-site">Site</label><select name="site" id="entry-form-field-site" class="form-select btn-touch"><option value="">None</option><?php foreach ($sites as $s): ?><option value="<?= (int) $s['site_id'] ?>" <?= ($cur['site_id'] ?? $prefill['site'] ?? null) === (int) $s['site_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
            </div>
        </div></div>
        <?php if ($cur === null): ?>
        <div class="card mb-3"><div class="card-header"><h5 class="card-title mb-0">Lines<?= $reverseOf !== null ? ' (the reversal, swapped)' : '' ?></h5><span class="fs-12 text-muted">More lines and the balance strip on the entry page</span></div>
            <div class="table-responsive"><table class="table table-sm mb-0" id="entry-form-lines">
                <thead><tr><th style="min-width:14rem">Account</th><th style="min-width:10rem">Description</th><th class="text-end" style="min-width:7rem">Debit</th><th class="text-end" style="min-width:7rem">Credit</th></tr></thead>
                <tbody><?php foreach ($rows as $i => $r): ?>
                    <tr id="entry-form-line-<?= $i ?>">
                        <td><?= account_select($pdo, 'line_account[]', $r['account'] === null ? null : (int) $r['account'], [], [], 'form="entry-form"', '— account —', 'entry-form-line-' . $i . '-account') ?></td>
                        <td><input type="text" name="line_description[]" class="form-control btn-touch" maxlength="300" value="<?= e($r['description'] ?? '') ?>" id="entry-form-line-<?= $i ?>-description"></td>
                        <td><input type="text" inputmode="decimal" name="line_debit[]" class="form-control btn-touch text-end" value="<?= e($r['debit'] !== null && (float) $r['debit'] > 0 ? $r['debit'] : '') ?>" id="entry-form-line-<?= $i ?>-debit"></td>
                        <td><input type="text" inputmode="decimal" name="line_credit[]" class="form-control btn-touch text-end" value="<?= e($r['credit'] !== null && (float) $r['credit'] > 0 ? $r['credit'] : '') ?>" id="entry-form-line-<?= $i ?>-credit"></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <div class="card-body fs-12 text-muted">A blank row is skipped. A line on a control account (1200, 2000) names its customer or vendor on the entry page before it posts.</div>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="entry-form-save-btn"><?= $cur === null ? 'Save the draft' : 'Save' ?></button>
        <?= hx_link($back[0], 'Cancel', 'btn btn-light btn-touch w-100', 'id="entry-form-cancel-link"') ?>
    </form>
</div>

<?php /** Prefixes and next numbers (screen `numbering`, a table with one form per row). Data: rows, notice */ ?>
<?= view('shared/header.php', ['id' => 'numbering', 'title' => 'Numbering', 'crumbs' => [['Home', '/'], ['Ledger settings', '/settings/general'], ['Numbering', null]], 'back' => back_link() ?? ['/settings/general', 'Ledger settings']]) ?>
<div class="main-content" id="numbering-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2">Invoices, credit notes and journal entries take their number when issued or posted; the rest at creation. A next number never goes down.</div>
    <div class="card"><div class="table-responsive"><table class="table mb-0" id="numbering-table">
        <thead><tr><th>Kind</th><th>Prefix</th><th>Next</th><th>Padding</th><th>Preview</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($rows as $r): $k = $r['kind']; ?>
                <tr id="numbering-row-<?= e($k) ?>">
                    <td class="fw-semibold align-middle"><?= e($r['label']) ?></td>
                    <td><input form="numbering-form-<?= e($k) ?>" type="text" name="prefix" class="form-control form-control-sm text-uppercase" maxlength="9" value="<?= e($r['prefix']) ?>" id="numbering-row-<?= e($k) ?>-prefix" style="min-width:5rem"></td>
                    <td><input form="numbering-form-<?= e($k) ?>" type="number" name="next_value" class="form-control form-control-sm" min="<?= $r['next_value'] ?>" value="<?= $r['next_value'] ?>" id="numbering-row-<?= e($k) ?>-next" style="min-width:5rem"></td>
                    <td><input form="numbering-form-<?= e($k) ?>" type="number" name="padding" class="form-control form-control-sm" min="1" max="12" value="<?= $r['padding'] ?>" id="numbering-row-<?= e($k) ?>-padding" style="min-width:4rem"></td>
                    <td class="align-middle"><code id="numbering-row-<?= e($k) ?>-preview"><?= e($r['preview']) ?></code></td>
                    <td class="text-end"><form method="post" action="/settings/numbering.php" hx-post="/settings/numbering.php" hx-target="#flash" id="numbering-form-<?= e($k) ?>"><?= csrf_field() ?><input type="hidden" name="kind" value="<?= e($k) ?>"><button type="submit" class="btn btn-sm btn-light" id="numbering-row-<?= e($k) ?>-save-btn">Save</button></form></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div>

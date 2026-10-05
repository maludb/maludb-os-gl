<?php /** The notes panel of a record (shared). Data: type, id, notes, may (note), here, tz, prefix */ $p = $prefix ?? 'record'; ?>
<div class="card mb-3" id="<?= e($p) ?>-notes"><div class="card-header"><h5 class="card-title mb-0">Notes</h5></div>
    <div class="list-group list-group-flush" id="<?= e($p) ?>-notes-list">
        <?php if ($notes === []): ?><div class="list-group-item text-muted fs-12" id="<?= e($p) ?>-notes-empty">No note yet.</div><?php endif; ?>
        <?php foreach ($notes as $n): ?>
            <div class="list-group-item" id="note-<?= (int) $n['note_id'] ?>">
                <div class="fs-12 text-muted"><?= e($n['member_name'] ?? 'Someone') ?><?= ($n['member_kind'] ?? '') === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?> · <?= e(format_ts($n['created_at'], $tz)) ?></div>
                <div><?= nl2br(e($n['body'])) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($may['note']): ?>
    <div class="card-body border-top">
        <form method="post" action="/notes/save.php" hx-post="/notes/save.php" hx-target="#flash" id="<?= e($p) ?>-note-form">
            <?= csrf_field() ?><input type="hidden" name="record_type" value="<?= e($type) ?>"><input type="hidden" name="record_id" value="<?= (int) $id ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <textarea name="body" class="form-control mb-2" rows="2" maxlength="4000" required placeholder="A note for whoever reads this next" id="<?= e($p) ?>-note-body"></textarea>
            <button type="submit" class="btn btn-light btn-touch w-100" id="<?= e($p) ?>-note-add-btn">Add a note</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php /** A site (screens `site-add`, `site-edit`). Data: cur */ $id = $cur['site_id'] ?? null; $screen = $cur === null ? 'site-add' : 'site-edit'; ?>
<?= view('shared/header.php', ['id' => $screen, 'title' => $cur === null ? 'Add a site' : 'Change ' . $cur['name'], 'crumbs' => [['Home', '/'], ['Sites', '/settings/sites/'], [$cur === null ? 'Add' : $cur['name'], null]], 'back' => back_link() ?? ['/settings/sites/', 'Sites']]) ?>
<div class="main-content" id="<?= e($screen) ?>-content">
    <form method="post" action="/settings/sites/save.php" hx-post="/settings/sites/save.php" hx-target="#flash" id="site-form">
        <?= csrf_field() ?><?php if ($id !== null): ?><input type="hidden" name="site" value="<?= (int) $id ?>"><?php endif; ?>
        <div class="card mb-3"><div class="card-body">
            <label class="form-label fs-12 text-muted" for="site-form-field-name">Name</label>
            <input type="text" name="name" id="site-form-field-name" class="form-control btn-touch mb-3" maxlength="120" required value="<?= e($cur['name'] ?? '') ?>" placeholder="Main Street">
            <label class="form-label fs-12 text-muted" for="site-form-field-location">OS location id (a number, from the OS's Work locations)</label>
            <input type="number" name="kernel_location" id="site-form-field-location" class="form-control btn-touch mb-3" min="1" value="<?= e((string) ($cur['kernel_location_id'] ?? '')) ?>">
            <label class="form-label fs-12 text-muted" for="site-form-field-address">Address</label>
            <textarea name="address" id="site-form-field-address" class="form-control" rows="2" maxlength="500"><?= e($cur['address'] ?? '') ?></textarea>
        </div></div>
        <button type="submit" class="btn btn-primary btn-touch w-100 mb-2" id="site-form-save-btn">Save</button>
        <?= hx_link('/settings/sites/', 'Cancel', 'btn btn-light btn-touch w-100', 'id="site-form-cancel-link"') ?>
    </form>
</div>

<?php /** The sites (screen `sites-list`, cards). Data: rows, includeArchived, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'sites-list', 'title' => 'Sites', 'crumbs' => [['Home', '/'], ['Ledger settings', '/settings/general'], ['Sites', null]],
    'action' => hx_link('/settings/sites/new', '<i class="feather-plus me-1"></i>Add site', 'btn btn-primary btn-touch', 'id="sites-list-add-btn"')]) ?>
<div class="main-content" id="sites-list-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="fs-12 text-muted mb-2">A site is a place the business trades from — a dimension on a journal line. The OS's Work locations give the id to pin.</div>
    <div class="row g-3" id="sites-list-cards">
        <?php if ($rows === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="sites-empty">No site yet — one is enough for a business under one roof.</div></div></div><?php endif; ?>
        <?php foreach ($rows as $s): $id = (int) $s['site_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100<?= $s['archived_at'] !== null ? ' opacity-75' : '' ?>" id="site-card-<?= $id ?>"><div class="card-body p-3">
                <div class="fw-bold" id="site-card-<?= $id ?>-name"><?= hx_link(with_back('/settings/sites/' . $id . '/edit', $here), e($s['name']), 'text-dark') ?></div>
                <div class="mt-1"><?php if ($s['kernel_location_id'] !== null): ?><span class="badge bg-soft-secondary text-secondary" id="site-card-<?= $id ?>-location"><i class="feather-map-pin me-1"></i>OS location #<?= (int) $s['kernel_location_id'] ?></span><?php endif; ?><?php if ($s['archived_at'] !== null): ?> <span class="badge bg-soft-danger text-danger">Archived</span><?php endif; ?></div>
                <?php if ($s['address']): ?><div class="fs-12 text-muted mt-1" id="site-card-<?= $id ?>-address"><?= nl2br(e($s['address'])) ?></div><?php endif; ?>
                <div class="row g-2 mt-2">
                    <div class="col-6"><?= hx_link(with_back('/settings/sites/' . $id . '/edit', $here), 'Change', 'btn btn-light btn-touch w-100', 'id="site-card-' . $id . '-edit-btn"') ?></div>
                    <div class="col-6"><form method="post" action="/settings/sites/archive.php" hx-post="/settings/sites/archive.php" hx-target="#flash" <?= $s['archived_at'] === null ? 'hx-confirm="Archive ' . e($s['name']) . '?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="site" value="<?= $id ?>"><input type="hidden" name="archived" value="<?= $s['archived_at'] === null ? 'yes' : 'no' ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="site-card-<?= $id ?>-archive-btn"><?= $s['archived_at'] === null ? 'Archive' : 'Restore' ?></button></form></div>
                </div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
    <div class="fs-12 mt-2"><?= hx_link('/settings/sites/' . ($includeArchived ? '' : '?include_archived=1'), $includeArchived ? 'Hide archived sites' : 'Show archived sites', '', 'id="sites-archived-link"') ?></div>
</div>

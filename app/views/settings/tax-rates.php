<?php /** The tax rates (screen `tax-rates`, a table). Data: rows, includeArchived, notice, here */ ?>
<?= view('shared/header.php', ['id' => 'tax-rates', 'title' => 'Tax rates', 'crumbs' => [['Home', '/'], ['Ledger settings', '/settings/general'], ['Tax rates', null]],
    'action' => hx_link('/settings/tax-rates/new', '<i class="feather-plus me-1"></i>Add rate', 'btn btn-primary btn-touch', 'id="tax-rates-add-btn"')]) ?>
<div class="main-content" id="tax-rates-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0" id="tax-rates-table">
        <thead><tr><th>Name</th><th class="text-end">Rate</th><th></th><th class="text-end"></th></tr></thead>
        <tbody>
            <?php foreach ($rows as $t): $id = (int) $t['tax_rate_id']; ?>
                <tr id="tax-rate-row-<?= $id ?>"<?= $t['archived_at'] !== null ? ' class="text-muted"' : '' ?>>
                    <td class="fw-semibold" id="tax-rate-row-<?= $id ?>-name"><?= e($t['name']) ?><?php if ($t['is_default']): ?> <span class="badge bg-soft-primary text-primary" id="tax-rate-row-<?= $id ?>-default">Default</span><?php endif; ?><?php if ($t['archived_at'] !== null): ?> <span class="badge bg-soft-danger text-danger">Archived</span><?php endif; ?></td>
                    <td class="text-end" id="tax-rate-row-<?= $id ?>-rate"><?= e(rtrim(rtrim(number_format((float) $t['rate'], 4, '.', ''), '0'), '.')) ?>%</td>
                    <td></td>
                    <td class="text-end text-nowrap">
                        <?php if ($t['archived_at'] === null): ?><?= hx_link(with_back('/settings/tax-rates/' . $id . '/edit', $here), 'Change', 'btn btn-sm btn-light', 'id="tax-rate-row-' . $id . '-edit-btn"') ?>
                            <?php if (!$t['is_default']): ?><form method="post" action="/settings/tax-rates/archive.php" hx-post="/settings/tax-rates/archive.php" hx-target="#flash" class="d-inline" hx-confirm="Archive <?= e($t['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="tax_rate" value="<?= $id ?>"><button type="submit" class="btn btn-sm btn-light" id="tax-rate-row-<?= $id ?>-archive-btn">Archive</button></form><?php endif; ?>
                        <?php else: ?><form method="post" action="/settings/tax-rates/archive.php" hx-post="/settings/tax-rates/archive.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tax_rate" value="<?= $id ?>"><input type="hidden" name="archived" value="no"><button type="submit" class="btn btn-sm btn-light" id="tax-rate-row-<?= $id ?>-restore-btn">Restore</button></form><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div></div>
    <div class="fs-12 mt-2"><?= hx_link('/settings/tax-rates/' . ($includeArchived ? '' : '?include_archived=1'), $includeArchived ? 'Hide archived rates' : 'Show archived rates', '', 'id="tax-rates-archived-link"') ?></div>
</div>

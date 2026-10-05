<?php /** One account (screen `account-view`). Data: a, range, activity, children, currency, bank, may, departments, sites, notice, here */ $id = (int) $a['account_id']; $sub = ACCOUNT_SUBTYPES[$a['account_type']][$a['subtype']] ?? $a['subtype']; ?>
<?= view('shared/header.php', ['id' => 'account-view', 'title' => $a['code'] . ' ' . $a['name'], 'crumbs' => [['Home', '/'], ['Chart of accounts', '/accounts/'], [$a['code'], null]], 'back' => back_link() ?? ['/accounts/', 'the chart'],
    'action' => $may['manage'] ? hx_link(with_back('/accounts/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-primary btn-touch', 'id="account-view-edit-btn"') : '']) ?>
<div class="main-content" id="account-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card" id="account-view-facts"><div class="card-body">
                <div class="fs-20 fw-bold" id="account-view-balance"><?= e(fmt_money($a['balance'], $currency)) ?></div>
                <div class="fs-12 text-muted mb-2">Balance as of <?= e(format_date($a['balance_as_of'])) ?> (<?= e($a['normal_balance']) ?> normal)</div>
                <div class="mb-2" id="account-view-chips">
                    <span class="badge bg-soft-secondary text-secondary"><?= e($a['type_label']) ?> · <?= e($sub) ?></span>
                    <?php if ($a['is_bank']): ?><span class="badge bg-soft-primary text-primary">Bank</span><?php endif; ?>
                    <?php if ($a['is_control']): ?><span class="badge bg-soft-warning text-warning">Control</span><?php endif; ?>
                    <?php if ($a['is_system']): ?><span class="badge bg-soft-dark text-dark">System</span><?php endif; ?>
                    <?php if ($a['archived_at'] !== null): ?><span class="badge bg-soft-danger text-danger" id="account-view-archived">Archived</span><?php endif; ?>
                </div>
                <?php if ($a['description']): ?><p class="mb-2" id="account-view-description"><?= e($a['description']) ?></p><?php endif; ?>
                <?php if ($a['is_control']): ?><div class="fs-12 text-muted mb-2" id="account-view-control-note">Takes no line without its customer or vendor.</div><?php endif; ?>
                <dl class="row mb-0 fs-12">
                    <dt class="col-5 text-muted">Parent</dt><dd class="col-7" id="account-view-parent"><?= $a['parent_id'] === null ? 'None' : hx_link(with_back('/accounts/' . (int) $a['parent_id'], $here), e($a['parent_code'] . ' ' . $a['parent_name'])) ?></dd>
                    <?php if ($a['is_bank']): ?><dt class="col-5 text-muted">Bank account</dt><dd class="col-7" id="account-view-bank"><?= $bank === null ? '<span class="text-muted">None yet (slice 6)</span>' : hx_link(with_back('/cash/banks/' . (int) $bank['id'], $here), e($bank['name'])) ?></dd><?php endif; ?>
                </dl>
                <?php if ($may['manage']): ?>
                    <form method="post" action="/accounts/archive.php" hx-post="/accounts/archive.php" hx-target="#flash" class="mt-3" <?= $a['archived_at'] === null ? 'hx-confirm="Archive ' . e($a['code'] . ' ' . $a['name']) . '? It takes no new line until restored."' : '' ?>>
                        <?= csrf_field() ?><input type="hidden" name="account" value="<?= $id ?>"><input type="hidden" name="archived" value="<?= $a['archived_at'] === null ? 'yes' : 'no' ?>">
                        <button type="submit" class="btn btn-light btn-touch w-100" id="account-view-archive-btn"><?= $a['archived_at'] === null ? 'Archive' : 'Restore' ?></button></form>
                <?php endif; ?>
            </div></div>
            <?php if ($children !== []): ?>
            <div class="card mt-3" id="account-view-children"><div class="card-header"><h5 class="card-title mb-0">Under this account</h5></div>
                <div class="list-group list-group-flush"><?php foreach ($children as $c): ?><div class="list-group-item d-flex justify-content-between" id="account-view-child-<?= (int) $c['account_id'] ?>"><?= hx_link(with_back('/accounts/' . (int) $c['account_id'], $here), e($c['code'] . ' ' . $c['name'])) ?><span class="text-nowrap"><?= e(fmt_money($c['balance'], $currency)) ?></span></div><?php endforeach; ?></div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-lg-8">
            <div class="card" id="account-view-activity-card"><div class="card-header"><h5 class="card-title mb-0">Activity</h5>
                <?php if ($may['export']): ?><a href="/accounts/<?= $id ?>?format=csv&amp;from=<?= e($range['from']) ?>&amp;to=<?= e($range['to']) ?>" class="fs-12" id="account-view-csv-link"><i class="feather-download me-1"></i>CSV</a><?php endif; ?></div>
                <div class="card-body border-bottom">
                    <form method="get" action="/accounts/<?= $id ?>" hx-get="/accounts/<?= $id ?>" hx-target="#account-activity" hx-swap="outerHTML" hx-trigger="change, submit" hx-push-url="true" id="account-activity-filters" class="row g-2 align-items-end">
                        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="account-activity-from">From</label><input type="date" name="from" id="account-activity-from" class="form-control btn-touch" value="<?= e($range['from']) ?>"></div>
                        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="account-activity-to">To</label><input type="date" name="to" id="account-activity-to" class="form-control btn-touch" value="<?= e($range['to']) ?>"></div>
                        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="account-activity-department">Department</label><select name="department" id="account-activity-department" class="form-select btn-touch"><option value="">Every department</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['department_id'] ?>" <?= $range['department'] === (int) $d['department_id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
                        <div class="col-6 col-md-3"><label class="form-label fs-12 text-muted" for="account-activity-site">Site</label><select name="site" id="account-activity-site" class="form-select btn-touch"><option value="">Every site</option><?php foreach ($sites as $s): ?><option value="<?= (int) $s['site_id'] ?>" <?= $range['site'] === (int) $s['site_id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                        <noscript><div class="col-12"><button type="submit" class="btn btn-light btn-touch w-100">Apply</button></div></noscript>
                    </form>
                </div>
                <?= view('accounts/activity-table.php', ['a' => $a, 'range' => $range, 'activity' => $activity, 'currency' => $currency, 'here' => $here]) ?>
            </div>
        </div>
    </div>
</div>

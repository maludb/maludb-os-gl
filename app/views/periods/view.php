<?php /** One period (screen `period-view`). Data: p, year, summary, checklist, history, currency, may (periods), yearClosable, notice, link, here, tz */ $id = (int) $p['fiscal_period_id']; $open = $p['status'] === 'open'; ?>
<?= view('shared/header.php', ['id' => 'period-view', 'title' => 'Period ' . $p['name'], 'crumbs' => [['Home', '/'], ['Periods', '/periods/'], [$p['fiscal_year_name'], '/periods/#fiscal-year-card-' . (int) $p['fiscal_year_id']], [$p['name'], null]], 'back' => back_link() ?? ['/periods/', 'Periods']]) ?>
<div class="main-content" id="period-view-content">
    <?= view('shared/notice.php', ['notice' => $notice, 'link' => $link]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card" id="period-view-facts"><div class="card-body">
                <div class="mb-2" id="period-view-status">
                    <?php if (!$open): ?><span class="badge bg-dark">Closed</span><?php elseif ((int) $p['reopen_count'] > 0): ?><span class="badge bg-warning text-dark">Reopened <?= (int) $p['reopen_count'] ?>×</span><?php else: ?><span class="badge bg-success">Open</span><?php endif; ?>
                    <span class="fs-12 text-muted ms-1"><?= e(format_date($p['start_date'])) ?> – <?= e(format_date($p['end_date'])) ?> · <?= e($p['fiscal_year_name']) ?><?= $year !== null && $year['status'] === 'closed' ? ' (year closed)' : '' ?></span>
                </div>
                <dl class="row mb-0 fs-12" id="period-view-history-facts">
                    <?php if ($p['closed_at'] !== null): ?><dt class="col-5 text-muted">Closed</dt><dd class="col-7"><?= e(format_ts($p['closed_at'], $tz)) ?> by <?= e($p['closed_by_name'] ?? '?') ?></dd><?php endif; ?>
                    <?php if ($p['reopened_at'] !== null): ?><dt class="col-5 text-muted">Reopened</dt><dd class="col-7"><?= e(format_ts($p['reopened_at'], $tz)) ?> by <?= e($p['reopened_by_name'] ?? '?') ?><div class="text-muted" id="period-view-reopen-reason">“<?= e($p['reopen_reason'] ?? '') ?>”</div></dd><?php endif; ?>
                    <dt class="col-5 text-muted">Reopened</dt><dd class="col-7" id="period-view-reopen-count"><?= (int) $p['reopen_count'] ?> time<?= (int) $p['reopen_count'] === 1 ? '' : 's' ?></dd>
                </dl>
                <?php if ($may['periods']): ?>
                    <?php if ($open): ?>
                        <form method="post" action="/periods/close.php" hx-post="/periods/close.php" hx-target="#flash" class="mt-3" hx-confirm="Close <?= e($p['name']) ?>? Nothing posts into it until it is reopened."><?= csrf_field() ?><input type="hidden" name="period" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="period-view-close-btn">Close the period</button></form>
                    <?php else: ?>
                        <form method="post" action="/periods/reopen.php" hx-post="/periods/reopen.php" hx-target="#flash" class="mt-3" hx-confirm="Reopen <?= e($p['name']) ?>? The reason is kept on the audit trail."><?= csrf_field() ?><input type="hidden" name="period" value="<?= $id ?>">
                            <label class="form-label fs-12 text-muted" for="period-reopen-reason">Why it is reopened</label><input type="text" name="reason" id="period-reopen-reason" class="form-control btn-touch mb-2" maxlength="500" required placeholder="A late vendor bill for March">
                            <button type="submit" class="btn btn-warning btn-touch w-100" id="period-view-reopen-btn">Reopen the period</button></form>
                    <?php endif; ?>
                    <?php if ($yearClosable): ?>
                        <form method="post" action="/periods/year-close.php" hx-post="/periods/year-close.php" hx-target="#flash" class="mt-2" hx-confirm="Close <?= e($p['fiscal_year_name']) ?>? Income and expenses close to retained earnings in one entry."><?= csrf_field() ?><input type="hidden" name="fiscal_year" value="<?= (int) $p['fiscal_year_id'] ?>"><button type="submit" class="btn btn-dark btn-touch w-100" id="period-view-year-close-btn">Close the year <?= e($p['fiscal_year_name']) ?></button></form>
                    <?php elseif ($p['is_last_of_year'] && $year !== null && $year['status'] === 'closed'): ?>
                        <form method="post" action="/periods/year-reopen.php" hx-post="/periods/year-reopen.php" hx-target="#flash" class="mt-2" hx-confirm="Reopen <?= e($p['fiscal_year_name']) ?>? Its closing entry is reversed."><?= csrf_field() ?><input type="hidden" name="fiscal_year" value="<?= (int) $p['fiscal_year_id'] ?>">
                            <label class="form-label fs-12 text-muted" for="year-reopen-reason">Why the year is reopened</label><input type="text" name="reason" id="year-reopen-reason" class="form-control btn-touch mb-2" maxlength="500" required>
                            <button type="submit" class="btn btn-outline-dark btn-touch w-100" id="period-view-year-reopen-btn">Reopen the year <?= e($p['fiscal_year_name']) ?></button></form>
                    <?php elseif ($p['is_last_of_year'] && $year !== null && $year['status'] === 'open'): ?><div class="fs-12 text-muted mt-2" id="period-view-year-note">The year closes from here once every period is closed.</div><?php endif; ?>
                <?php endif; ?>
            </div></div>
            <div class="card mt-3" id="period-view-posted"><div class="card-header"><h5 class="card-title mb-0">What posted</h5><?= hx_link(with_back('/journal/?period=' . $id, $here), 'Journal', 'fs-12') ?></div>
                <?php if ($summary['posted'] === []): ?><div class="card-body text-muted" id="period-view-posted-empty">Nothing posted yet.</div><?php else: ?>
                <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Source</th><th class="text-end">Entries</th><th class="text-end">Total</th></tr></thead><tbody>
                    <?php foreach ($summary['posted'] as $s): ?><tr id="period-view-posted-<?= e($s['source']) ?>"><td><?= hx_link(with_back('/journal/?period=' . $id . '&source=' . $s['source'], $here), e($s['source'])) ?></td><td class="text-end"><?= $s['entries'] ?></td><?= fmt_money_cell($s['total'], $currency) ?></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
                <div class="card-body border-top d-flex justify-content-between" id="period-view-drafts"><span>Still a draft</span><span><?= hx_link(with_back('/journal/?period=' . $id . '&status=draft', $here), '<span id="period-view-drafts-count">' . (int) $summary['drafts'] . '</span> draft' . ((int) $summary['drafts'] === 1 ? '' : 's')) ?></span></div>
            </div>
        </div>
        <div class="col-lg-7">
            <?= view('periods/partials/checklist.php', ['checklist' => $checklist, 'here' => $here]) ?>
            <div class="card mt-3" id="period-view-history"><div class="card-header"><h5 class="card-title mb-0">History</h5></div>
                <?php if ($history === []): ?><div class="card-body text-muted" id="period-view-history-empty">Never closed.</div><?php else: ?>
                <div class="list-group list-group-flush"><?php foreach ($history as $i => $h): ?><div class="list-group-item fs-12" id="period-view-history-<?= $i ?>"><span class="fw-semibold"><?= e(str_replace(['period.', 'fiscal_year.'], ['', 'year '], $h['action'])) ?></span> by <?= e($h['by'] ?? '?') ?>, <?= e(format_ts($h['at'], $tz)) ?><?= isset($h['after']['reason']) ? ' — “' . e($h['after']['reason']) . '”' : '' ?></div><?php endforeach; ?></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

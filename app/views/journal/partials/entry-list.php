<?php /** The list region (Pattern B; swapped by the filters; listens to journalEntryChanged). Data: rows, total, page, totals, filters, may, balancedDrafts, here */
$qs = http_build_query(array_filter($filters, static fn ($v) => $v !== '' && $v !== null)); $pages = (int) ceil($total / ENTRY_PAGE); ?>
<div id="entry-list" hx-get="/journal/?<?= e($qs) ?>" hx-trigger="journalEntryChanged from:body" hx-target="#entry-list" hx-swap="outerHTML">
    <?php if ($rows === []): ?>
        <div class="card"><div class="card-body text-center text-muted py-4" id="entry-list-empty">No entries yet. The first is usually the opening balances.<?= $may['write'] ? '<div class="mt-2">' . hx_link('/journal/new', 'New entry', 'btn btn-primary btn-touch', 'id="entry-list-new-btn"') . '</div>' : '' ?></div></div>
    <?php else: ?>
        <?php if ($may['write'] && $balancedDrafts !== []): ?>
            <form method="post" action="/journal/post-batch.php" hx-post="/journal/post-batch.php" hx-target="#flash" class="mb-2" hx-confirm="Post the <?= count($balancedDrafts) ?> balanced draft<?= count($balancedDrafts) === 1 ? '' : 's' ?> listed here?"><?= csrf_field() ?><input type="hidden" name="entries" value="<?= e(implode(',', $balancedDrafts)) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
                <button type="submit" class="btn btn-light btn-touch" id="entry-list-post-batch-btn"><i class="feather-check-circle me-1"></i>Post all balanced drafts (<?= count($balancedDrafts) ?>)</button></form>
        <?php endif; ?>
        <div class="d-md-none" id="entry-list-cards"><?php foreach ($rows as $e): ?><?= view('journal/partials/entry-card.php', ['e' => $e, 'here' => $here]) ?><?php endforeach; ?></div>
        <div class="card d-none d-md-block"><div class="table-responsive"><table class="table table-hover mb-0" id="entry-list-table">
            <thead><tr><th>Number</th><th>Date</th><th>Period</th><th>Source</th><th>Memo</th><th class="text-end">Total</th><th>Status</th><th>By</th></tr></thead>
            <tbody><?php foreach ($rows as $e): ?><?= view('journal/partials/entry-row.php', ['e' => $e, 'here' => $here]) ?><?php endforeach; ?></tbody>
            <tfoot id="entry-list-totals"><?php foreach ($totals as $t): ?><tr><td colspan="5" class="text-muted fs-12">Posted, <?= e($t['currency']) ?> (<?= $t['entries'] ?> entr<?= $t['entries'] === 1 ? 'y' : 'ies' ?>)</td><?= fmt_money_cell($t['total'], $t['currency'], 'entry-list-total-' . $t['currency']) ?><td colspan="2"></td></tr><?php endforeach; ?></tfoot>
        </table></div></div>
        <div class="d-md-none fs-12 text-muted mt-2" id="entry-list-totals-phone"><?php foreach ($totals as $t): ?><div>Posted, <?= e($t['currency']) ?>: <?= e(fmt_money($t['total'], $t['currency'])) ?> (<?= $t['entries'] ?>)</div><?php endforeach; ?></div>
        <div class="d-flex justify-content-between align-items-center mt-2 fs-12" id="entry-list-pager">
            <span class="text-muted"><?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?><?= $pages > 1 ? ', page ' . $page . ' of ' . $pages : '' ?></span>
            <span><?php if ($page > 1): ?><?= hx_link('/journal/?' . $qs . '&page=' . ($page - 1), 'Newer', 'btn btn-sm btn-light', 'id="entry-list-prev"') ?> <?php endif; ?><?php if ($page < $pages): ?><?= hx_link('/journal/?' . $qs . '&page=' . ($page + 1), 'Older', 'btn btn-sm btn-light', 'id="entry-list-next"') ?><?php endif; ?>
                <?php if ($may['export']): ?><a href="/journal/?<?= e($qs) ?>&amp;format=csv" class="btn btn-sm btn-light" id="entry-list-csv-link"><i class="feather-download me-1"></i>CSV</a><?php endif; ?></span>
        </div>
    <?php endif; ?>
</div>

<?php /** One entry (screen `journal-entry-view`). Data: e, balance, attachments, notes, timeline, may, pdo, departments, sites, taxRates, here, tz, notice */
$id = (int) $e['journal_entry_id']; $title = $e['number'] ?? 'Draft #' . $id; $draft = $e['status'] === 'draft'; $postable = $draft && $balance['balances'] && $e['period_status'] === 'open'; ?>
<?= view('shared/header.php', ['id' => 'journal-entry-view', 'title' => $title, 'crumbs' => [['Home', '/'], ['Journal', '/journal/'], [$title, null]], 'back' => back_link() ?? ['/journal/', 'the journal'],
    'action' => $draft && $may['write'] ? hx_link(with_back('/journal/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch', 'id="entry-view-edit-btn"') : '']) ?>
<div class="main-content" id="journal-entry-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="entry-head"><div class="card-body p-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="badge bg-<?= ENTRY_STATUS_BADGE[$e['status']] ?>" id="entry-head-status"><?= e($e['status']) ?></span>
            <span id="entry-head-date"><?= e(format_date($e['entry_date'])) ?></span>
            <?php if ($e['period_name'] !== null): ?><span class="badge bg-<?= $e['period_status'] === 'closed' ? 'dark' : ((int) $e['period_reopen_count'] > 0 ? 'warning text-dark' : 'success') ?>" id="entry-head-period"><?= e($e['period_name']) ?><?= $e['period_status'] === 'closed' ? ' closed' : '' ?></span><?php else: ?><span class="badge bg-soft-danger text-danger" id="entry-head-period">no period</span><?php endif; ?>
            <span class="text-muted" id="entry-head-source"><?= e($e['source_label']) ?></span>
            <?php if ($e['department_name'] !== null): ?><span class="badge bg-soft-secondary text-secondary"><?= e($e['department_name']) ?></span><?php endif; ?><?php if ($e['site_name'] !== null): ?><span class="badge bg-soft-secondary text-secondary"><i class="feather-map-pin me-1"></i><?= e($e['site_name']) ?></span><?php endif; ?>
            <span class="ms-auto fw-bold fs-16" id="entry-head-total"><?= e(fmt_money($e['total'], $e['currency'])) ?></span>
        </div>
        <?php if ($e['memo'] !== null): ?><div class="mt-2" id="entry-head-memo"><?= e($e['memo']) ?></div><?php endif; ?>
        <div class="fs-12 text-muted mt-1" id="entry-head-people">By <?= e($e['created_by_name'] ?? '?') ?><?= $e['created_by_kind'] === 'agent' ? ' <span class="badge bg-soft-info text-info">agent</span>' : '' ?>, <?= e(format_ts($e['created_at'], $tz)) ?><?= $e['posted_at'] !== null ? ' · posted by ' . e($e['posted_by_name'] ?? '?') . ', ' . e(format_ts($e['posted_at'], $tz)) : '' ?><?= $e['voided_at'] !== null ? ' · voided ' . e(format_ts($e['voided_at'], $tz)) . ($e['void_reason'] !== null ? ': “' . e($e['void_reason']) . '”' : '') : '' ?></div>
        <?= view('journal/partials/source-link.php', ['e' => $e, 'here' => $here]) ?>
    </div></div>
    <?php if ($draft): ?><?= view('journal/partials/balance-strip.php', ['e' => $e, 'balance' => $balance, 'may' => $may]) ?><?php endif; ?>
    <?= view('journal/partials/lines.php', ['e' => $e, 'balance' => $balance, 'pdo' => $pdo, 'may' => $may, 'departments' => $departments, 'sites' => $sites, 'taxRates' => $taxRates]) ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <?php if ($draft && $may['write']): ?>
                <div class="card mb-3" id="entry-actions"><div class="card-body">
                    <div class="fs-12 text-muted mb-2">Post is on the balance strip above; it follows the lines.</div>
                    <form method="post" action="/journal/void.php" hx-post="/journal/void.php" hx-target="#flash" hx-confirm="Void this draft? It stays on the trail as void."><?= csrf_field() ?><input type="hidden" name="entry" value="<?= $id ?>">
                        <label class="form-label fs-12 text-muted" for="entry-void-reason">Why it is voided</label><input type="text" name="reason" id="entry-void-reason" class="form-control btn-touch mb-2" maxlength="500">
                        <button type="submit" class="btn btn-light btn-touch w-100" id="entry-void-btn">Void the draft</button></form>
                </div></div>
            <?php elseif ($e['status'] === 'posted'): ?>
                <div class="card mb-3" id="entry-actions"><div class="card-body">
                    <?php if ($e['has_document']): ?><div class="fs-12 text-muted" id="entry-reverse-from-document">Reverse it from the <?= e(record_words((string) $e['source_type'])) ?> (void it) so the document and the books agree.</div>
                    <?php elseif ($may['reverse']): ?>
                        <form method="post" action="/journal/reverse.php" hx-post="/journal/reverse.php" hx-target="#flash" hx-confirm="Reverse <?= e($title) ?>? A new entry with the sides swapped is posted."><?= csrf_field() ?><input type="hidden" name="entry" value="<?= $id ?>">
                            <label class="form-label fs-12 text-muted" for="entry-reverse-reason">Why it is reversed</label><input type="text" name="reason" id="entry-reverse-reason" class="form-control btn-touch mb-2" maxlength="500" required>
                            <label class="form-label fs-12 text-muted" for="entry-reverse-date">Dated<?= $e['period_status'] === 'closed' ? ' (its period is closed: today)' : ' (empty: the original\'s date)' ?></label><input type="date" name="reverse_date" id="entry-reverse-date" class="form-control btn-touch mb-2" value="<?= $e['period_status'] === 'closed' ? e(date('Y-m-d')) : '' ?>">
                            <button type="submit" class="btn btn-warning btn-touch w-100" id="entry-reverse-btn">Reverse</button></form>
                    <?php else: ?><div class="fs-12 text-muted" id="entry-reverse-not-yours">Only its author, or someone who may override, reverses it.</div><?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?= view('shared/attachments.php', ['type' => 'journal_entry', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'entry']) ?>
            <?= view('shared/notes.php', ['type' => 'journal_entry', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'entry']) ?>
        </div>
        <div class="col-lg-7"><?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'entry']) ?></div>
    </div>
</div>

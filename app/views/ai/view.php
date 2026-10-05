<?php /** One month (screen `ai-statement-view`). Data: s, lines, check, attachments, notes, timeline, may, here, tz, notice */ $id = (int) $s['ai_statement_id']; $h = $s['header']; ?>
<?= view('shared/header.php', ['id' => 'ai-statement-view', 'title' => 'AI usage ' . $s['period'], 'crumbs' => [['Home', '/'], ['AI statements', '/ap/ai/'], [$s['period'], null]], 'back' => back_link() ?? ['/ap/ai/', 'AI statements']]) ?>
<div class="main-content" id="ai-statement-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3"><div class="col-lg-4">
        <div class="card mb-3" id="ai-statement-head"><div class="card-body">
            <div class="d-flex gap-1 mb-2"><span class="badge bg-<?= $s['kernel_status'] === 'closed' ? 'secondary' : 'warning' ?>" id="ai-statement-head-kernel"><?= $s['kernel_status'] === 'closed' ? 'closed' : 'open in the OS' ?></span><span class="badge bg-<?= AI_STATUS_BADGE[$s['status']] ?>" id="ai-statement-head-status"><?= e($s['status']) ?></span></div>
            <?php foreach ($s['totals'] as $cur => $amt): ?><div class="fs-20 fw-bold" id="ai-statement-head-total-<?= e((string) $cur) ?>"><?= e(fmt_money((string) $amt, (string) $cur)) ?></div><?php endforeach; ?>
            <dl class="row mb-0 fs-12 mt-2">
                <dt class="col-5 text-muted">Business</dt><dd class="col-7"><?= e((string) ($h['business'] ?? '')) ?></dd>
                <dt class="col-5 text-muted">Period</dt><dd class="col-7"><?= e(format_date($s['period_start'])) ?> – <?= e(format_date($s['period_end'])) ?></dd>
                <dt class="col-5 text-muted">Generated</dt><dd class="col-7" id="ai-statement-head-generated"><?= e((string) ($h['generated_at'] ?? '')) ?></dd>
                <dt class="col-5 text-muted">Pulled</dt><dd class="col-7"><?= e(format_ts($s['pulled_at'], $tz)) ?></dd>
                <?php if ($h['exchange']): ?><dt class="col-5 text-muted">Exchange</dt><dd class="col-7" id="ai-statement-head-exchange"><?= e((string) $h['exchange']) ?></dd><?php endif; ?>
                <?php if ($s['late_calls'] > 0): ?><dt class="col-5 text-muted">Late calls</dt><dd class="col-7 text-warning" id="ai-statement-head-late"><?= (int) $s['late_calls'] ?> folded in</dd><?php endif; ?>
                <dt class="col-5 text-muted">Hash</dt><dd class="col-7 text-truncate" title="<?= e($s['document_hash']) ?>"><?= e(substr($s['document_hash'], 0, 12)) ?>…</dd>
            </dl>
        </div></div>
        <?php if ($may['import'] || $may['check'] || $may['pull']): ?><div class="card mb-3" id="ai-statement-actions"><div class="card-body">
            <?php if ($may['import']): ?><form method="post" action="/ap/ai/import.php" hx-post="/ap/ai/import.php" hx-target="#flash" hx-confirm="Import <?= e($s['period']) ?>? One draft bill is made per provider and currency." class="mb-2"><?= csrf_field() ?><input type="hidden" name="ai_statement" value="<?= $id ?>"><button type="submit" class="btn btn-primary btn-touch w-100" id="ai-statement-import-btn">Import as bills</button></form>
            <?php elseif ($s['status'] === 'pulled' && $s['kernel_status'] === 'open'): ?><div class="fs-12 text-muted mb-2" id="ai-statement-import-why">Still open in the OS: only a closed month is imported.</div><?php endif; ?>
            <?php if ($may['check']): ?><form method="post" action="/ap/ai/check.php" hx-post="/ap/ai/check.php" hx-target="#flash" class="mb-2"><?= csrf_field() ?><input type="hidden" name="ai_statement" value="<?= $id ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="ai-statement-check-btn">Check the bills</button></form><?php endif; ?>
            <?php if ($may['pull'] && $s['status'] === 'pulled'): ?><form method="post" action="/ap/ai/pull.php" hx-post="/ap/ai/pull.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="period" value="<?= e($s['period']) ?>"><button type="submit" class="btn btn-light btn-touch w-100" id="ai-statement-pull-btn">Pull again</button></form><?php endif; ?>
        </div></div><?php endif; ?>
        <?= view('shared/attachments.php', ['type' => 'ai_statement', 'id' => $id, 'attachments' => $attachments, 'may' => $may, 'here' => $here, 'prefix' => 'ai-statement']) ?>
        <?= view('shared/notes.php', ['type' => 'ai_statement', 'id' => $id, 'notes' => $notes, 'may' => $may, 'here' => $here, 'tz' => $tz, 'prefix' => 'ai-statement']) ?>
    </div><div class="col-lg-8">
        <?= view('ai/partials/bills-check.php', ['s' => $s, 'check' => $check, 'may' => $may, 'here' => $here]) ?>
        <?= view('ai/partials/lines-table.php', ['s' => $s, 'lines' => $lines]) ?>
        <?= view('shared/timeline.php', ['rows' => $timeline, 'tz' => $tz, 'prefix' => 'ai-statement']) ?>
    </div></div>
</div>

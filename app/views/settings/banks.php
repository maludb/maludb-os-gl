<?php /** The bank accounts' settings (screen `bank-settings`). Data: banks, here, notice */ ?>
<?= view('shared/header.php', ['id' => 'bank-settings', 'title' => 'Bank accounts (settings)', 'crumbs' => [['Home', '/'], ['Settings', '/settings/'], ['Bank accounts', null]], 'action' => hx_link('/settings/banks/new', '<i class="feather-plus me-1"></i>Add a bank account', 'btn btn-primary btn-touch', 'id="bank-settings-add-btn"')]) ?>
<div class="main-content" id="bank-settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="row g-3" id="bank-settings-cards">
        <?php if ($banks === []): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-4" id="bank-settings-empty">No bank account yet. A bank account stands over a ledger account flagged as a bank (1000 or 1010 in the standard chart).</div></div></div><?php endif; ?>
        <?php foreach ($banks as $b): $id = (int) $b['bank_account_id']; ?>
            <div class="col-12 col-md-6 col-xl-4"><div class="card h-100<?= $b['archived_at'] !== null ? ' opacity-75' : '' ?>" id="bank-setting-<?= $id ?>"><div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start gap-2"><div class="fw-bold"><?= hx_link(with_back('/settings/banks/' . $id . '/edit', $here), e($b['name']), 'text-dark') ?></div><span class="badge bg-soft-secondary text-secondary"><?= e(BANK_KINDS[$b['kind']] ?? $b['kind']) ?></span></div>
                <div class="fs-12 text-muted">Ledger <?= e($b['account_code']) ?> · <?= e($b['currency']) ?><?= $b['archived_at'] !== null ? ' · archived' : '' ?></div>
                <div class="fs-12 mt-1">Opening <?= e(fmt_money($b['opening_balance'], $b['currency'])) ?> on <?= e(format_date($b['opening_date'])) ?></div>
                <div class="row g-2 mt-2"><div class="col-6"><?= hx_link(with_back('/settings/banks/' . $id . '/edit', $here), 'Edit', 'btn btn-light btn-touch w-100', 'id="bank-setting-' . $id . '-edit-btn"') ?></div><div class="col-6"><?= hx_link(with_back('/cash/banks/' . $id, $here), 'Open', 'btn btn-light btn-touch w-100', 'id="bank-setting-' . $id . '-open-btn"') ?></div></div>
            </div></div></div>
        <?php endforeach; ?>
    </div>
</div>

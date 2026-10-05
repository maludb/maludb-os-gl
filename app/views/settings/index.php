<?php /** My settings (screen `settings`). Data: tab, prefs, refusal, osChannels, notice, recent, may (settings) */ $may = $may ?? ['settings' => false]; ?>
<?= view('shared/header.php', ['id' => 'settings', 'title' => 'My settings', 'crumbs' => [['Home', '/'], ['My settings', null]]]) ?>
<div class="main-content" id="settings-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-1 mb-3" id="settings-tabs">
        <?= hx_link('/settings/?tab=notify', 'How I am told', 'btn btn-touch ' . ($tab === 'notify' ? 'btn-primary' : 'btn-light'), 'id="settings-tab-notify"') ?>
        <?= hx_link('/settings/tokens/', 'Tokens', 'btn btn-touch btn-light', 'id="settings-tab-tokens"') ?>
        <?php if ($may['settings']): ?><?= hx_link('/settings/general', 'Ledger settings', 'btn btn-touch btn-light', 'id="settings-tab-general"') ?><?php endif; ?>
    </div>
    <?= view('settings/partials/notify.php', ['prefs' => $prefs, 'refusal' => $refusal, 'osChannels' => $osChannels, 'recent' => $recent ?? []]) ?>
</div>

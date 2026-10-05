<?php /** One report (screen `reports`). Data: slug, title, tables, data, range, asOf, groupBy, customer, vendor, pt, extra, periods, customers, vendors, currency, mixed */
$asOfOnly = in_array($slug, ['ar-aging', 'ap-aging', 'control-check'], true);
$fields = '';
if ($slug === 'spend' || $slug === 'revenue') {
    $opts = $slug === 'spend' ? ['account', 'vendor', 'department', 'site'] : ['account', 'customer', 'department', 'site'];
    $fields .= '<div class="col-6 col-md-2"><label class="form-label fs-12 text-muted" for="period-picker-group-by">Group by</label><select name="group_by" id="period-picker-group-by" class="form-select btn-touch">';
    foreach ($opts as $o) { $fields .= '<option value="' . e($o) . '"' . ($groupBy === $o ? ' selected' : '') . '>' . e(REPORT_GROUPS[$o]) . '</option>'; }
    $fields .= '</select></div>';
}
if ($slug === 'customer-statement' || $slug === 'ar-aging') {
    $fields .= '<div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="period-picker-customer">Customer</label><select name="customer" id="period-picker-customer" class="form-select btn-touch"><option value="">' . ($slug === 'ar-aging' ? 'All' : 'Choose a customer…') . '</option>';
    foreach ($customers as $c) { $fields .= '<option value="' . (int) $c['customer_id'] . '"' . ($customer === (int) $c['customer_id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>'; }
    $fields .= '</select></div>';
}
if ($slug === 'ap-aging') {
    $fields .= '<div class="col-12 col-md-3"><label class="form-label fs-12 text-muted" for="period-picker-vendor">Vendor</label><select name="vendor" id="period-picker-vendor" class="form-select btn-touch"><option value="">All</option>';
    foreach ($vendors as $v) { $fields .= '<option value="' . (int) $v['vendor_id'] . '"' . ($vendor === (int) $v['vendor_id'] ? ' selected' : '') . '>' . e($v['name']) . '</option>'; }
    $fields .= '</select></div>';
}
$range2 = $range; $range2['as_of'] = $asOf; ?>
<?= view('shared/header.php', ['id' => 'report', 'title' => $title, 'crumbs' => [['Home', '/'], ['Statements', '/statements/'], [$title, null]], 'back' => back_link() ?? ['/statements/', 'Statements']]) ?>
<div class="main-content" id="report-content" data-report="<?= e($slug) ?>">
    <?= view('statements/partials/posted-through.php', ['pt' => $pt, 'currency' => $currency, 'mixed' => $mixed]) ?>
    <?= view('statements/partials/period-picker.php', ['action' => '/reports/' . $slug, 'range' => $range2, 'periods' => $periods, 'asOf' => $asOfOnly, 'mode' => null, 'extraFields' => $fields]) ?>
    <?php if ($slug === 'customer-statement' && $customer === null): ?>
        <div class="card"><div class="card-body text-center text-muted py-4" id="report-choose-customer">Choose a customer to see their statement.</div></div>
    <?php elseif ($tables === [] || ($slug !== 'control-check' && array_sum(array_map(static fn (array $t): int => count($t['rows']), $tables)) <= count($tables) && in_array($slug, ['ar-aging', 'ap-aging'], true))): ?>
        <div class="card"><div class="card-body text-center text-muted py-4" id="report-empty"><?= in_array($slug, ['ar-aging', 'ap-aging'], true) ? 'Nothing is owed.' : 'Nothing to report for that period.' ?></div></div>
    <?php else: ?>
        <?php foreach ($tables as $t): ?><?= view('statements/partials/statement-table.php', ['table' => $t, 'id' => 'report']) ?><?php endforeach; ?>
        <?php if ($slug === 'control-check' && $data !== null && !$data['ok']): ?>
            <div class="alert alert-danger fs-12" id="report-control-note"><?php foreach ($data['rows'] as $r): if ($r['ok']) { continue; } ?>
                <div>The ledger holds <?= e(fmt_money($r['ledger'], $currency)) ?> of <?= e($r['control'] === 'receivable' ? 'receivables' : 'payables') ?> and the documents hold <?= e(fmt_money($r['subledger'], $currency)) ?> — <?= (float) $r['difference'] < 0 ? 'an opening balance entry or a manual line sits behind the difference' : 'documents are open that the ledger does not hold' ?>; the audit page lists the lines.</div>
            <?php endforeach; ?></div>
        <?php endif; ?>
        <?php if (in_array($slug, ['ar-aging', 'ap-aging'], true)): ?><div class="fs-12 text-muted">Each party opens its page; the document lists follow in slices 4 and 5.</div><?php endif; ?>
    <?php endif; ?>
</div>

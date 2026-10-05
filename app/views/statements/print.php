<?php /** The print page: the bare layout, the business's name and address, the period, the statement, posted through. Data: title, table|tables, range, pt, biz, hasBooks */
$list = ($tables ?? null) ?? [$table]; ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?></title>
<style>body{font:13px/1.4 system-ui,sans-serif;margin:24px;color:#111}h1{font-size:18px;margin:0}h2{font-size:15px;margin:18px 0 6px}table{border-collapse:collapse;width:100%}th,td{padding:3px 6px;border-bottom:1px solid #ddd;text-align:left}th.n,td.n{text-align:right}.head td{font-weight:600;background:#f4f4f4}.subtotal td{font-weight:600}.total td{font-weight:700;border-top:2px solid #111}.muted{color:#666}@media print{body{margin:0}}</style></head>
<body id="print-page">
<h1 id="print-business"><?= e($biz['business_name'] ?? '') ?></h1>
<?php if (!empty($biz['business_address'])): ?><div class="muted" id="print-address"><?= nl2br(e($biz['business_address'])) ?></div><?php endif; ?>
<h2 id="print-title"><?= e($title) ?> — <?= e($range['label']) ?></h2>
<div class="muted" id="print-posted-through"><?= e($pt['sentence']) ?></div>
<?php if (!$hasBooks): ?><p>No books for that period.</p><?php endif; ?>
<?php foreach ($hasBooks ? $list : [] as $t): $tc = (int) $t['text_cols']; ?>
    <h2><?= e($t['title']) ?> <span class="muted">(<?= e($t['currency']) ?>)</span></h2>
    <?php if (!empty($t['status'])): ?><div><?= e($t['status']['text']) ?></div><?php endif; ?>
    <table><thead><tr><?php foreach ($t['columns'] as $i => $c): ?><th class="<?= $i >= $tc ? 'n' : '' ?>"><?= e($c) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($t['rows'] as $r): ?><tr class="<?= e($r['kind']) ?>"><?php foreach ($r['cells'] as $i => $cell): ?><td class="<?= $i >= $tc ? 'n' : '' ?>"><?= $i >= $tc && is_numeric($cell) ? e(fmt_money($cell, $t['currency'], false)) : e((string) $cell) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table>
<?php endforeach; ?>
</body></html>

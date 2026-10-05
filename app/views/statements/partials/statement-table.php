<?php /** One generic statement table (screen and shared with print). Data: table (see present.php), id */
$id = $id ?? 'statement'; $tc = (int) $table['text_cols']; $n = count($table['columns']); ?>
<div class="card mb-3" id="<?= e($id) ?>-<?= e(strtolower($table['currency'])) ?>">
    <div class="card-header"><h5 class="card-title mb-0"><?= e($table['title']) ?></h5><span class="badge bg-soft-secondary text-secondary" id="<?= e($id) ?>-currency"><?= e($table['currency']) ?></span></div>
    <?php if (!empty($table['status'])): ?><div class="px-3 pt-2"><span class="badge bg-<?= $table['status']['ok'] ? 'success' : 'danger' ?> fs-12" id="<?= e($id) ?>-status"><?= e($table['status']['text']) ?></span></div><?php endif; ?>
    <div class="table-responsive"><table class="table table-sm mb-0" id="<?= e($id) ?>-table">
        <thead><tr><?php foreach ($table['columns'] as $i => $c): ?><th class="<?= $i >= $tc && $i < ($table['money_to'] ?? $n) + 1 && $i >= $tc ? 'text-end' : '' ?>"><?= e($c) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
            <?php foreach ($table['rows'] as $r): $k = $r['kind']; $cls = $k === 'head' ? 'table-light fw-semibold' : ($k === 'subtotal' ? 'fw-semibold' : ($k === 'total' ? 'fw-bold border-top' : '')); ?>
                <tr class="<?= $cls ?>"<?= $k === 'check' ? ' id="' . e($id) . '-check" data-ok="' . ($r['ok'] ? 'yes' : 'no') . '"' : '' ?>>
                    <?php foreach ($r['cells'] as $i => $cell): $money = $i >= $tc && ($cell === '' || is_numeric($cell)) && !(isset($table['money_to']) && $i > $table['money_to']); ?>
                        <?php if ($money): $txt = $cell === '' ? '' : e(fmt_money($cell, $table['currency'], false)); ?><td class="text-end text-nowrap<?= $k === 'check' ? ' text-' . ($r['ok'] ? 'success' : 'danger') : '' ?>"><?= isset($r['links'][$i]) && (float) $cell != 0 ? hx_link($r['links'][$i], $txt) : $txt ?></td>
                        <?php else: ?><td><?= $i === ($tc - 1) && $r['url'] !== null ? hx_link($r['url'], e((string) $cell)) : e((string) $cell) ?></td><?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

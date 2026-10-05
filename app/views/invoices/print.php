<?php /** The print-ready invoice (the bare layout), shared with the customer's secure page. Data: inv, biz, cust, footer, public (bool), token (the raw token, public only) */
$cur = $inv['currency']; ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title><?= e((string) ($inv['number'] ?? 'Invoice')) ?></title>
<style>body{font:14px/1.45 system-ui,sans-serif;margin:0;color:#111;background:#f5f5f5}.page{max-width:820px;margin:16px auto;background:#fff;padding:24px;box-sizing:border-box}h1{font-size:20px;margin:0}table{border-collapse:collapse;width:100%}th,td{padding:5px 6px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}.n{text-align:right;white-space:nowrap}.muted{color:#666}.tot td{border:0;padding:2px 6px}.big td{font-weight:700;border-top:2px solid #111}.box{border:1px solid #ddd;border-radius:6px;padding:12px;margin-top:16px}.tbl{overflow-x:auto}.btn{display:inline-block;padding:10px 14px;border:1px solid #888;border-radius:6px;background:#fff;color:#111;text-decoration:none;font-size:14px;min-height:44px;box-sizing:border-box}input,select,textarea{font:inherit;padding:8px;width:100%;box-sizing:border-box;min-height:44px}label{display:block;margin:8px 0 2px;font-size:12px;color:#666}@media print{body{background:#fff}.page{margin:0;padding:0}.noprint{display:none}}</style></head>
<body id="print-page"><div class="page">
<div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap">
    <div><h1 id="print-business"><?= e($biz['business_name'] ?? '') ?></h1><?php if (!empty($biz['business_address'])): ?><div class="muted" id="print-address"><?= nl2br(e($biz['business_address'])) ?></div><?php endif; ?></div>
    <div style="text-align:right"><h1 id="print-number"><?= $inv['status'] === 'void' ? 'VOID ' : '' ?>Invoice <?= e((string) ($inv['number'] ?? '')) ?></h1><div class="muted">Issued <?= e(format_date($inv['issue_date'])) ?> · due <?= e(format_date($inv['due_date'])) ?></div><?php if ($inv['reference']): ?><div class="muted">Your reference <?= e($inv['reference']) ?></div><?php endif; ?></div>
</div>
<div class="box" id="print-customer"><strong><?= e($inv['customer_name']) ?></strong><?php if (!empty($cust['billing_address'])): ?><br><?= nl2br(e($cust['billing_address'])) ?><?php endif; ?></div>
<?php if ($inv['memo']): ?><p id="print-memo"><?= nl2br(e($inv['memo'])) ?></p><?php endif; ?>
<div class="tbl"><table id="print-lines" style="margin-top:16px"><thead><tr><th>Description</th><th class="n">Qty</th><th class="n">Unit price</th><th class="n">Tax</th><th class="n">Amount</th></tr></thead><tbody>
    <?php foreach ($inv['lines'] as $l): ?><tr><td><?= e($l['description']) ?></td><td class="n"><?= e(rtrim(rtrim((string) $l['quantity'], '0'), '.')) ?></td><td class="n"><?= e(fmt_money($l['unit_price'], $cur, false)) ?></td><td class="n"><?= (float) $l['tax_rate'] > 0 ? e(rtrim(rtrim((string) $l['tax_rate'], '0'), '.')) . '%' : '' ?></td><td class="n"><?= e(fmt_money($l['line_subtotal'], $cur, false)) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<table class="tot" style="width:auto;margin-left:auto;margin-top:12px" id="print-totals">
    <tr><td class="muted">Subtotal</td><td class="n"><?= e(fmt_money($inv['subtotal'], $cur)) ?></td></tr><tr><td class="muted">Tax</td><td class="n"><?= e(fmt_money($inv['tax_total'], $cur)) ?></td></tr>
    <tr><td><strong>Total</strong></td><td class="n"><strong><?= e(fmt_money($inv['total'], $cur)) ?></strong></td></tr>
    <?php if ($inv['status'] !== 'draft'): ?><tr><td class="muted">Received</td><td class="n" id="print-received"><?= e(fmt_money(m_add($inv['amount_paid'], $inv['amount_credited']), $cur)) ?></td></tr><tr class="big"><td>Balance due</td><td class="n" id="print-balance"><?= e(fmt_money($inv['balance_due'], $cur)) ?></td></tr><?php endif; ?>
</table>
<?php if (!empty($public) && ($inv['receipts'] !== [] || $inv['credits'] !== [])): ?>
    <div class="box" id="public-received"><strong>Received</strong><?php foreach ($inv['receipts'] as $r): ?><div><?= e(format_date($r['received_on'])) ?> — <?= e(fmt_money($r['amount'], $cur)) ?></div><?php endforeach; ?><?php foreach ($inv['credits'] as $c): ?><div>Credit <?= e((string) $c['credit_note_number']) ?> — <?= e(fmt_money($c['amount'], $cur)) ?></div><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($inv['terms_text']): ?><p class="muted" id="print-terms"><?= nl2br(e($inv['terms_text'])) ?></p><?php endif; ?>
<?php if ($footer !== ''): ?><p class="muted" id="print-footer"><?= nl2br(e($footer)) ?></p><?php endif; ?>
<?php if (!empty($public) && empty($printMode)): ?>
    <div class="noprint" style="margin-top:16px"><a class="btn" id="public-print-btn" href="/i/<?= e((string) $token) ?>/print" target="_blank" rel="noopener">Print</a></div>
    <?= $publicExtra ?? '' ?>
<?php endif; ?>
</div></body></html>

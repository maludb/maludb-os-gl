<?php /** "I paid this": the claim form on the customer's page. Data: inv, token, methods. The honeypot field is hidden from people. */ ?>
<div class="box noprint" id="claim-box"><strong>Already paid?</strong><div class="muted" style="margin-bottom:8px">Tell the business, so they can match it. This does not make a payment.</div>
<form method="post" action="/i/<?= e($token) ?>/claim" id="claim-form"><?= csrf_field() ?>
    <div style="position:absolute;left:-5000px" aria-hidden="true"><label for="claim-website">Leave this empty</label><input type="text" name="website" id="claim-website" tabindex="-1" autocomplete="off"></div>
    <label for="claim-date">I paid on</label><input type="date" name="claimed_on" id="claim-date" required value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>">
    <label for="claim-method">By</label><select name="method" id="claim-method"><?php foreach ($methods as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select>
    <label for="claim-reference">Reference (cheque number, bank reference)</label><input type="text" name="reference" id="claim-reference" maxlength="120">
    <label for="claim-amount">Amount</label><input type="text" inputmode="decimal" name="amount" id="claim-amount" value="<?= e($inv['balance_due']) ?>">
    <label for="claim-note">A note (optional)</label><textarea name="note" id="claim-note" rows="2" maxlength="500"></textarea>
    <button type="submit" class="btn" id="claim-submit-btn" style="margin-top:12px;width:100%">I paid this</button>
</form></div>

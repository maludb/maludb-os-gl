<?php /** The customer's statement (screen `customer-statement`). Data: data, table, range, periods, may (send), notice, here */ $id = (int) $data['customer_id']; ?>
<?= view('shared/header.php', ['id' => 'customer-statement', 'title' => 'Statement — ' . $data['customer_name'], 'crumbs' => [['Home', '/'], ['Customers', '/customers/'], [$data['customer_name'], '/customers/' . $id], ['Statement', null]], 'back' => back_link() ?? ['/customers/' . $id, 'the customer']]) ?>
<div class="main-content" id="customer-statement-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?= view('statements/partials/period-picker.php', ['action' => '/customers/' . $id . '/statement', 'range' => $range, 'periods' => $periods, 'asOf' => false, 'mode' => null]) ?>
    <?= view('statements/partials/statement-table.php', ['table' => $table, 'id' => 'customer-statement']) ?>
    <?php if ($may['send']): ?>
        <form method="post" action="/customers/statement-send.php" hx-post="/customers/statement-send.php" hx-target="#flash" hx-confirm="Send this statement to <?= e($data['customer_name']) ?>?" class="row g-2">
            <?= csrf_field() ?><input type="hidden" name="customer" value="<?= $id ?>"><input type="hidden" name="from" value="<?= e($range['from']) ?>"><input type="hidden" name="to" value="<?= e($range['to']) ?>"><input type="hidden" name="return_to" value="<?= e($here) ?>">
            <div class="col-12 col-md-8"><input type="email" name="to_email" class="form-control btn-touch" placeholder="To (empty: the customer's primary contact, else the customer)" maxlength="200" id="customer-statement-to"></div>
            <div class="col-12 col-md-4"><button type="submit" class="btn btn-primary btn-touch w-100" id="customer-statement-send-btn">Send the statement</button></div>
        </form>
    <?php endif; ?>
</div>

<?php
declare(strict_types=1);

require_can('sales');

$id = (int) get('id');
$sale = sale_totals($id);
if (!$sale) {
    flash('Sale not found.', 'err');
    redirect('sales');
}
$me = (int) user()['id'];

if (is_post()) {
    $action = post('action');
    $balance = round((float) $sale['total'] - (float) $sale['paid'], 2);

    if ($action === 'pay' && can('payments')) {
        $amount = round(num(post('amount')), 2);
        $paidAt = valid_date(post('paid_date')) ? post('paid_date') : date('Y-m-d');
        if ($sale['status'] !== 'active') {
            flash('This sale is cancelled.', 'err');
        } elseif ($amount <= 0) {
            flash('Enter an amount greater than zero.', 'err');
        } elseif ($amount > $balance + 0.004) {
            flash('That is more than the balance due (' . money($balance) . ').', 'err');
        } else {
            q('INSERT INTO payments (sale_id, amount, method, reference, paid_at, received_by, note, created_at) VALUES (?,?,?,?,?,?,?,?)', [
                $id, $amount, pick(post('method'), array_keys(PAY_METHODS), 'cash'), mb_substr(post('reference'), 0, 80) ?: null,
                $paidAt . ' ' . date('H:i:s'), $me, mb_substr(post('note'), 0, 255) ?: null, date('Y-m-d H:i:s'),
            ]);
            log_activity('payment_add', $sale['receipt_no'] . ' ' . money($amount));
            flash('Payment of ' . money($amount) . ' recorded.');
        }
    } elseif ($action === 'del_payment' && can('void')) {
        $pid = (int) post('payment_id');
        $pay = row('SELECT * FROM payments WHERE id = ? AND sale_id = ?', [$pid, $id]);
        if ($pay) {
            q('DELETE FROM payments WHERE id = ?', [$pid]);
            log_activity('payment_delete', $sale['receipt_no'] . ' ' . money($pay['amount']));
            flash('Payment removed.');
        }
    } elseif ($action === 'cancel' && can('void')) {
        if ((float) $sale['paid'] > 0) {
            flash('Remove the recorded payments first (refund the client), then cancel the sale.', 'err');
        } else {
            q("UPDATE sales SET status = 'cancelled' WHERE id = ?", [$id]);
            log_activity('sale_cancel', $sale['receipt_no']);
            flash('Sale cancelled.');
        }
    } elseif ($action === 'restore' && can('void')) {
        q("UPDATE sales SET status = 'active' WHERE id = ?", [$id]);
        log_activity('sale_restore', $sale['receipt_no']);
        flash('Sale restored.');
    }
    redirect('sale_view', ['id' => $id]);
}

$client = row('SELECT * FROM clients WHERE id = ?', [$sale['client_id']]);
$items = rows('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$id]);
$payments = rows('SELECT p.*, u.name AS receiver FROM payments p LEFT JOIN users u ON u.id = p.received_by WHERE p.sale_id = ? ORDER BY p.paid_at, p.id', [$id]);
$seller = $sale['sold_by'] ? val('SELECT name FROM users WHERE id = ?', [$sale['sold_by']]) : null;
$booking = $sale['booking_id'] ? row('SELECT id, title, shoot_date, status FROM bookings WHERE id = ?', [$sale['booking_id']]) : null;
$jobs = rows('SELECT j.*, u.name AS editor FROM jobs j LEFT JOIN users u ON u.id = j.assigned_to WHERE j.sale_id = ? ORDER BY j.id', [$id]);
$balance = max(0, round((float) $sale['total'] - (float) $sale['paid'], 2));
$status = pay_status($sale);

$wa = wa_number((string) ($client['phone'] ?? ''));
$waText = 'Hello ' . $client['name'] . ', this is ' . setting('studio_name', 'DotCliq Studio') . '. Receipt ' . $sale['receipt_no'] . ': total ' . money($sale['total']) . ', paid ' . money($sale['paid']) . ', balance ' . money($balance) . '. Thank you!';

page_start('Sale ' . $sale['receipt_no'], 'sales');
page_head($sale['receipt_no'], 'Logged ' . fmt_date($sale['sale_date']) . ($seller ? ' by ' . $seller : ''),
    badge($status)
    . '<a class="btn ghost" target="_blank" href="' . e(url('receipt', ['id' => $id])) . '">' . icon('printer', 16) . ' Receipt</a>'
    . ($wa ? '<a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/' . e($wa) . '?text=' . rawurlencode($waText) . '">' . icon('phone', 16) . ' WhatsApp</a>' : '')
    . (can('payments') && $balance > 0 && $sale['status'] === 'active' ? '<button class="btn gold" data-open="payDlg">' . icon('plus', 16) . ' Record payment</button>' : ''));
?>
<div class="grid g-main">
  <div>
    <div class="card">
      <div class="card-h"><h2><?= icon('tag') ?> Services</h2></div>
      <div class="tbl-wrap"><table class="tbl stack">
        <thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Unit price</th><th class="r">Total</th></tr></thead>
        <tbody><?php foreach ($items as $it): ?>
          <tr><td data-label="Item"><?= e($it['description']) ?></td><td data-label="Qty" class="r"><?= (int) $it['qty'] ?></td><td data-label="Unit" class="r"><?= money($it['unit_price']) ?></td><td data-label="Total" class="r"><b><?= money($it['line_total']) ?></b></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>

    <div class="card">
      <div class="card-h"><h2><?= icon('card') ?> Payments</h2></div>
      <?php if (!$payments): ?><p class="muted">No payments recorded yet.</p><?php else: ?>
      <div class="tbl-wrap"><table class="tbl stack">
        <thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Received by</th><th class="r">Amount</th><th></th></tr></thead>
        <tbody><?php foreach ($payments as $p): ?>
          <tr>
            <td data-label="Date" class="nowrap"><?= e(fmt_date(substr((string) $p['paid_at'], 0, 10))) ?><span class="sub-line"><?= e($p['note']) ?></span></td>
            <td data-label="Method"><?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?></td>
            <td data-label="Reference"><?= e($p['reference'] ?: '—') ?></td>
            <td data-label="By"><?= e($p['receiver'] ?: '—') ?></td>
            <td data-label="Amount" class="r green-t"><b><?= money($p['amount']) ?></b></td>
            <td class="act"><?php if (can('void')): ?><form method="post" data-confirm="Remove this payment?"><?= csrf_field() ?><input type="hidden" name="action" value="del_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>"><button class="btn sm danger" type="submit" aria-label="Remove payment"><?= icon('trash', 14) ?></button></form><?php endif; ?></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <?php if ($jobs || $booking): ?>
    <div class="card">
      <div class="card-h"><h2><?= icon('layers') ?> Workflow</h2></div>
      <div class="mini-list">
        <?php if ($booking): ?><div class="mini"><div class="l"><b>Shoot: <?= e($booking['title']) ?></b><span class="sub-line"><?= e(fmt_date($booking['shoot_date'], 'D, d M Y')) ?></span></div><?= badge($booking['status']) ?></div><?php endif; ?>
        <?php foreach ($jobs as $j): ?><div class="mini"><div class="l"><b><?= e($j['title']) ?></b><span class="sub-line"><?= e($j['editor'] ?: 'Unassigned') ?><?= $j['due_date'] ? ' · due ' . e(fmt_date($j['due_date'])) : '' ?></span></div><?= badge($j['status']) ?></div><?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-h"><h2>Summary</h2></div>
      <div class="sum">
        <div class="l"><span class="muted">Subtotal</span><span><?= money($sale['subtotal']) ?></span></div>
        <?php if ((float) $sale['discount'] > 0): ?><div class="l"><span class="muted">Discount</span><span class="red-t">− <?= money($sale['discount']) ?></span></div><?php endif; ?>
        <div class="l t"><span>Grand total</span><span><?= money($sale['total']) ?></span></div>
        <div class="l"><span class="muted">Paid</span><span class="green-t"><?= money($sale['paid']) ?></span></div>
        <div class="l"><span class="muted">Balance due</span><b class="<?= $balance > 0 ? 'gold-t' : 'green-t' ?>"><?= money($balance) ?></b></div>
      </div>
      <?php $pct = (float) $sale['total'] > 0 ? min(100, (float) $sale['paid'] / (float) $sale['total'] * 100) : 0; ?>
      <div class="bar green"><i style="width:<?= round($pct) ?>%"></i></div>
    </div>

    <div class="card">
      <div class="card-h"><h2>Client</h2></div>
      <dl class="dl" style="grid-template-columns:1fr">
        <div><dt>Name</dt><dd><a href="<?= e(url('client_view', ['id' => $client['id']])) ?>"><?= e($client['name']) ?></a></dd></div>
        <div><dt>Phone</dt><dd><?= e($client['phone'] ?: '—') ?></dd></div>
        <?php if ($sale['notes']): ?><div><dt>Notes</dt><dd><?= nl2br(e($sale['notes'])) ?></dd></div><?php endif; ?>
      </dl>
      <?php if (can('assign') && !$jobs): ?><hr><a class="btn ghost sm" href="<?= e(url('job_form', ['sale' => $id])) ?>"><?= icon('layers', 14) ?> Add editing job</a><?php endif; ?>
    </div>

    <?php if (can('void')): ?>
    <div class="card">
      <?php if ($sale['status'] === 'active'): ?>
        <form method="post" data-confirm="Cancel this sale? It will be excluded from reports."><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn danger sm" type="submit">Cancel sale</button></form>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><button class="btn ghost sm" type="submit">Restore sale</button></form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (can('payments') && $balance > 0 && $sale['status'] === 'active'): ?>
<dialog id="payDlg">
  <div class="dlg-h"><h2>Record payment</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x', 18) ?></button></div>
  <form method="post" class="dlg-b">
    <?= csrf_field() ?><input type="hidden" name="action" value="pay">
    <p class="muted">Balance due: <b class="gold-t"><?= money($balance) ?></b></p>
    <div class="grid2">
      <label class="fld"><span>Amount *</span><input name="amount" inputmode="decimal" value="<?= e((string) $balance) ?>" required></label>
      <label class="fld"><span>Date</span><input type="date" name="paid_date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></label>
      <label class="fld"><span>Method</span><select name="method"><?php foreach (PAY_METHODS as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'mpesa' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="fld"><span>Reference / M-Pesa code</span><input name="reference" maxlength="80"></label>
    </div>
    <label class="fld"><span>Note</span><input name="note" maxlength="255" placeholder="e.g. Balance on delivery"></label>
    <div class="form-actions"><button class="btn gold" type="submit">Save payment</button><button class="btn ghost" type="button" data-close>Cancel</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php page_end();

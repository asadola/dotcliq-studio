<?php
declare(strict_types=1);

require_can('clients');

$id = (int) get('id');
$client = row('SELECT * FROM clients WHERE id = ?', [$id]);
if (!$client) {
    flash('Client not found.', 'err');
    redirect('clients');
}

if (is_post()) {
    $action = post('action');
    if ($action === 'update') {
        $name = post('name');
        if ($name === '') {
            flash('Name is required.', 'err');
        } else {
            q('UPDATE clients SET name = ?, phone = ?, email = ?, notes = ? WHERE id = ?', [
                mb_substr($name, 0, 140), mb_substr(post('phone'), 0, 40) ?: null, mb_substr(post('email'), 0, 160) ?: null, post('notes') ?: null, $id,
            ]);
            log_activity('client_edit', $name);
            flash('Client updated.');
        }
    } elseif ($action === 'delete' && can('void')) {
        $used = (int) val('SELECT COUNT(*) FROM sales WHERE client_id = ?', [$id]) + (int) val('SELECT COUNT(*) FROM bookings WHERE client_id = ?', [$id]) + (int) val('SELECT COUNT(*) FROM jobs WHERE client_id = ?', [$id]);
        if ($used > 0) {
            flash('This client has sales, bookings or jobs and cannot be deleted.', 'err');
        } else {
            q('DELETE FROM clients WHERE id = ?', [$id]);
            log_activity('client_delete', $client['name']);
            flash('Client deleted.');
            redirect('clients');
        }
    }
    redirect('client_view', ['id' => $id]);
}

$sales = rows('SELECT s.*, COALESCE(p.paid,0) AS paid ' . SALES_FROM . ' WHERE s.client_id = ? ORDER BY s.sale_date DESC, s.id DESC', [$id]);
$bookings = rows('SELECT b.*, u.name AS staff FROM bookings b LEFT JOIN users u ON u.id = b.assigned_to WHERE b.client_id = ? ORDER BY b.shoot_date DESC LIMIT 20', [$id]);
$billed = $paid = 0.0;
foreach ($sales as $s) {
    if ($s['status'] === 'active') {
        $billed += (float) $s['total'];
        $paid += (float) $s['paid'];
    }
}

page_start($client['name'], 'clients');
$wa = wa_number((string) $client['phone']);
page_head($client['name'], 'Client since ' . fmt_date(substr((string) $client['created_at'], 0, 10)),
    ($wa ? '<a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/' . e($wa) . '">' . icon('phone', 16) . ' WhatsApp</a>' : '')
    . (can('assign') ? '<a class="btn ghost" href="' . e(url('booking_form', ['client' => $id])) . '">' . icon('calendar', 16) . ' Book shoot</a>' : '')
    . (can('sales') ? '<a class="btn" href="' . e(url('sale_form', ['client' => $id])) . '">' . icon('plus', 16) . ' New sale</a>' : ''));
?>
<div class="grid g4">
  <div class="card kpi"><span class="lbl">Total billed</span><span class="val"><?= money($billed) ?></span></div>
  <div class="card kpi green"><span class="lbl">Total paid</span><span class="val"><?= money($paid) ?></span></div>
  <div class="card kpi <?= $billed - $paid > 0.004 ? 'hl' : '' ?>"><span class="lbl">Outstanding</span><span class="val"><?= money(max(0, $billed - $paid)) ?></span></div>
  <div class="card kpi"><span class="lbl">Visits</span><span class="val"><?= count(array_filter($sales, fn($s) => $s['status'] === 'active')) ?></span></div>
</div>

<div class="grid g-main">
  <div>
    <div class="card">
      <div class="card-h"><h2><?= icon('sales') ?> Sales history</h2></div>
      <?php if (!$sales): ?><p class="muted">No sales yet.</p><?php else: ?>
      <div class="tbl-wrap"><table class="tbl stack">
        <thead><tr><th>Date</th><th>Receipt</th><th class="r">Total</th><th class="r">Balance</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sales as $s): $bal = max(0, (float) $s['total'] - (float) $s['paid']); ?>
          <tr>
            <td data-label="Date"><?= e(fmt_date($s['sale_date'])) ?></td>
            <td data-label="Receipt"><?= e($s['receipt_no']) ?></td>
            <td data-label="Total" class="r"><?= money($s['total']) ?></td>
            <td data-label="Balance" class="r"><?= $s['status'] === 'cancelled' ? '—' : money($bal) ?></td>
            <td data-label="Status"><?= badge(pay_status($s)) ?></td>
            <td class="act"><?php if (can('sales')): ?><a class="btn sm ghost" href="<?= e(url('sale_view', ['id' => $s['id']])) ?>">View</a><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <div class="card">
      <div class="card-h"><h2><?= icon('calendar') ?> Bookings</h2></div>
      <?php if (!$bookings): ?><p class="muted">No bookings yet.</p><?php else: ?>
      <div class="mini-list">
      <?php foreach ($bookings as $b): ?>
        <div class="mini"><div class="l"><b><?= e($b['title']) ?></b><span class="sub-line"><?= e(fmt_date($b['shoot_date'], 'D, d M Y')) ?> <?= e(fmt_time($b['start_time'])) ?> · <?= e($b['staff'] ?: 'Unassigned') ?></span></div><?= badge($b['status']) ?></div>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Client details</h2></div>
    <form method="post" class="stack">
      <?= csrf_field() ?><input type="hidden" name="action" value="update">
      <label class="fld"><span>Full name</span><input name="name" value="<?= e($client['name']) ?>" required maxlength="140"></label>
      <label class="fld"><span>Phone</span><input name="phone" value="<?= e($client['phone']) ?>" inputmode="tel" maxlength="40"></label>
      <label class="fld"><span>Email</span><input type="email" name="email" value="<?= e($client['email']) ?>" maxlength="160"></label>
      <label class="fld"><span>Notes</span><textarea name="notes"><?= e($client['notes']) ?></textarea></label>
      <div class="form-actions"><button class="btn" type="submit">Save changes</button></div>
    </form>
    <?php if (can('void')): ?>
      <hr>
      <form method="post" data-confirm="Delete this client permanently?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger sm" type="submit"><?= icon('trash', 14) ?> Delete client</button></form>
    <?php endif; ?>
  </div>
</div>
<?php page_end();

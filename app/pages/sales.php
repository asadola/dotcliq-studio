<?php
declare(strict_types=1);

require_can('sales');

$statusF = pick(get('status'), ['unpaid', 'partial', 'paid', 'cancelled'], '');
$staffF = (int) get('staff');
$search = get('q');

$params = [];
$where = '1=1';
[$from, $to, $periodKey] = [null, null, 'all'];
// period handled below after output of filter bar, so compute first without printing
$key = pick(get('period', 'month'), ['all', 'today', 'week', 'month', 'year', 'custom'], 'month');
[$from, $to] = period_range($key, get('from'), get('to'));
$where .= range_cond('s.sale_date', $from, $to, $params);

if ($statusF === 'cancelled') {
    $where .= " AND s.status = 'cancelled'";
} elseif ($statusF !== '') {
    $where .= " AND s.status = 'active'";
    if ($statusF === 'unpaid') $where .= ' AND COALESCE(p.paid,0) <= 0';
    if ($statusF === 'partial') $where .= ' AND COALESCE(p.paid,0) > 0 AND COALESCE(p.paid,0) < s.total - 0.004';
    if ($statusF === 'paid') $where .= ' AND COALESCE(p.paid,0) >= s.total - 0.004';
}
if ($staffF > 0) {
    $where .= ' AND s.sold_by = ?';
    $params[] = $staffF;
}
if ($search !== '') {
    $where .= ' AND (c.name LIKE ? OR c.phone LIKE ? OR s.receipt_no LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}

$sum = row("SELECT COUNT(*) AS n, COALESCE(SUM(s.total),0) AS billed, COALESCE(SUM(COALESCE(p.paid,0)),0) AS paid " . SALES_FROM . " WHERE $where AND s.status = 'active'", $params);

$select = "SELECT s.*, c.name AS client, c.phone, COALESCE(p.paid,0) AS paid, u.name AS seller,
    (SELECT description FROM sale_items si WHERE si.sale_id = s.id ORDER BY si.id LIMIT 1) AS first_item,
    (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_n "
    . SALES_FROM . " LEFT JOIN users u ON u.id = s.sold_by WHERE $where ORDER BY s.sale_date DESC, s.id DESC";

if (get('export') === '1') {
    $out = [];
    foreach (rows($select . ' LIMIT 5000', $params) as $r) {
        $out[] = [$r['sale_date'], $r['receipt_no'], $r['client'], $r['phone'], $r['first_item'] . ($r['item_n'] > 1 ? ' +' . ($r['item_n'] - 1) . ' more' : ''), $r['subtotal'], $r['discount'], $r['total'], $r['paid'], max(0, (float) $r['total'] - (float) $r['paid']), pay_status($r), $r['seller']];
    }
    log_activity('export', 'Sales CSV');
    csv_download('sales-' . date('Ymd') . '.csv', ['Date', 'Receipt', 'Client', 'Phone', 'Items', 'Subtotal', 'Discount', 'Total', 'Paid', 'Balance', 'Status', 'Sold by'], $out);
}

$total = (int) val("SELECT COUNT(*) " . SALES_FROM . " WHERE $where", $params);
$pg = paginate($total, 25);
$list = rows($select . " LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$staff = staff_list();
$keep = ['period' => $key, 'from' => get('from'), 'to' => get('to'), 'status' => $statusF, 'staff' => $staffF ?: '', 'q' => $search];

page_start('Sales', 'sales');
page_head('Sales', period_label($from, $to) . ' · ' . $sum['n'] . ' sale' . ((int) $sum['n'] === 1 ? '' : 's'),
    '<a class="btn ghost" href="' . e(url('sales', $keep + ['export' => 1])) . '">' . icon('download', 16) . ' Export CSV</a>'
    . '<a class="btn" href="' . e(url('sale_form')) . '">' . icon('plus', 16) . ' New sale</a>');
period_filter('sales', array_filter(['status' => $statusF, 'staff' => $staffF ?: '', 'q' => $search], fn($v) => $v !== ''), true);
?>
<div class="grid g3">
  <div class="card kpi"><span class="lbl">Billed</span><span class="val"><?= money($sum['billed']) ?></span></div>
  <div class="card kpi green"><span class="lbl">Collected</span><span class="val"><?= money($sum['paid']) ?></span></div>
  <div class="card kpi <?= $sum['billed'] - $sum['paid'] > 0.004 ? 'hl' : '' ?>"><span class="lbl">Uncollected balance</span><span class="val"><?= money(max(0, $sum['billed'] - $sum['paid'])) ?></span></div>
</div>

<div class="card">
  <form method="get" action="index.php" class="row between" style="margin-bottom:14px">
    <input type="hidden" name="p" value="sales"><input type="hidden" name="period" value="<?= e($key) ?>"><input type="hidden" name="from" value="<?= e(get('from')) ?>"><input type="hidden" name="to" value="<?= e(get('to')) ?>">
    <div class="search" style="flex:1;min-width:200px"><?= icon('search', 16) ?><input name="q" value="<?= e($search) ?>" placeholder="Search client, phone or receipt no."></div>
    <div class="row">
      <select name="status" onchange="this.form.submit()" style="width:auto" aria-label="Payment status"><option value="">All payments</option><?php foreach (['unpaid' => 'Unpaid', 'partial' => 'Part-paid', 'paid' => 'Fully paid', 'cancelled' => 'Cancelled'] as $k => $l): ?><option value="<?= $k ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
      <select name="staff" onchange="this.form.submit()" style="width:auto" aria-label="Sold by"><option value="">All staff</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $staffF === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
      <button class="btn ghost sm" type="submit">Search</button>
    </div>
  </form>

<?php if (!$list): ?>
  <div class="empty"><h2>No sales found</h2><p>Nothing recorded for this timeframe and filter.</p><a class="btn" href="<?= e(url('sale_form')) ?>">Log a sale</a></div>
<?php else: ?>
  <div class="tbl-wrap"><table class="tbl stack">
    <thead><tr><th>Date</th><th>Client</th><th>Service / package</th><th class="r">Grand total</th><th class="r">Paid</th><th class="r">Balance</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $s): $bal = max(0, (float) $s['total'] - (float) $s['paid']); $st = pay_status($s); ?>
      <tr>
        <td data-label="Date" class="nowrap"><?= e(fmt_date($s['sale_date'], 'd M')) ?><span class="sub-line"><?= e($s['receipt_no']) ?></span></td>
        <td data-label="Client"><a href="<?= e(url('sale_view', ['id' => $s['id']])) ?>"><b><?= e($s['client']) ?></b></a><span class="sub-line"><?= e($s['phone']) ?></span></td>
        <td data-label="Service"><?= e($s['first_item']) ?><?= $s['item_n'] > 1 ? '<span class="sub-line">+' . ((int) $s['item_n'] - 1) . ' more</span>' : '' ?></td>
        <td data-label="Total" class="r"><b><?= money($s['total']) ?></b></td>
        <td data-label="Paid" class="r green-t"><?= money($s['paid']) ?></td>
        <td data-label="Balance" class="r <?= $bal > 0 && $st !== 'cancelled' ? 'gold-t' : '' ?>"><?= $st === 'cancelled' ? '—' : money($bal) ?></td>
        <td data-label="Status"><?= badge($st) ?></td>
        <td class="act">
          <a class="btn sm ghost" href="<?= e(url('sale_view', ['id' => $s['id']])) ?>"><?= icon('eye', 14) ?> <?= $bal > 0 && $st !== 'cancelled' && can('payments') ? 'Pay' : 'View' ?></a>
          <a class="btn sm ghost" target="_blank" href="<?= e(url('receipt', ['id' => $s['id']])) ?>" title="Print receipt"><?= icon('printer', 14) ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager($pg, 'sales', $keep) ?>
<?php endif; ?>
</div>
<?php page_end();

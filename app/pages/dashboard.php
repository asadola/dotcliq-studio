<?php
declare(strict_types=1);

require_can('dashboard');
$u = user();
$me = (int) $u['id'];
$today = date('Y-m-d');
$hour = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$first = explode(' ', trim($u['name']))[0];

/* ================= Photographer / Editor dashboards ================= */
if (is_scoped()) {
    $shoots = rows(
        "SELECT b.*, c.name AS client, c.phone FROM bookings b JOIN clients c ON c.id = b.client_id
         WHERE b.assigned_to = ? AND b.shoot_date >= ? AND b.status IN ('pending','confirmed') ORDER BY b.shoot_date, b.start_time LIMIT 12",
        [$me, $today]
    );
    $myJobs = rows(
        "SELECT j.*, c.name AS client FROM jobs j JOIN clients c ON c.id = j.client_id
         WHERE j.assigned_to = ? AND j.status <> 'delivered' ORDER BY (j.priority = 'urgent') DESC, j.due_date IS NULL, j.due_date LIMIT 12",
        [$me]
    );
    $doneWeek = (int) val("SELECT COUNT(*) FROM jobs WHERE assigned_to = ? AND status = 'delivered' AND delivered_at >= ?", [$me, date('Y-m-d 00:00:00', strtotime('monday this week'))]);
    $shootsMonth = (int) val("SELECT COUNT(*) FROM bookings WHERE assigned_to = ? AND status = 'completed' AND shoot_date BETWEEN ? AND ?", [$me, date('Y-m-01'), date('Y-m-t')]);
    $overdue = count(array_filter($myJobs, fn($j) => $j['due_date'] && $j['due_date'] < $today));

    page_start('Dashboard', 'dashboard');
    page_head($greet . ', ' . $first, role_label($u['role']) . ' · ' . date('l, d F Y'));
    ?>
    <div class="grid g4">
      <div class="card kpi"><span class="lbl">Upcoming shoots</span><span class="val"><?= count($shoots) ?></span><div class="sub"><span>Assigned to you</span></div><span class="ki"><?= icon('camera') ?></span></div>
      <div class="card kpi"><span class="lbl">Open jobs</span><span class="val"><?= count($myJobs) ?></span><div class="sub"><span>Overdue: <b><?= $overdue ?></b></span></div><span class="ki"><?= icon('layers') ?></span></div>
      <div class="card kpi green"><span class="lbl">Delivered this week</span><span class="val"><?= $doneWeek ?></span><span class="ki"><?= icon('check') ?></span></div>
      <div class="card kpi"><span class="lbl">Shoots done this month</span><span class="val"><?= $shootsMonth ?></span><span class="ki"><?= icon('calendar') ?></span></div>
    </div>
    <div class="grid g2">
      <div class="card">
        <div class="card-h"><h2><?= icon('calendar') ?> My upcoming shoots</h2><a class="btn sm ghost" href="<?= e(url('bookings')) ?>">All</a></div>
        <?php if (!$shoots): ?><p class="muted">Nothing scheduled — enjoy the breather.</p><?php endif; ?>
        <div class="mini-list"><?php foreach ($shoots as $b): ?>
          <div class="mini"><div class="l"><b><?= e($b['title']) ?></b><span class="sub-line"><?= e(fmt_date($b['shoot_date'], 'D, d M')) ?> <?= e(fmt_time($b['start_time'])) ?> · <?= e($b['client']) ?><?= $b['location'] ? ' · ' . e($b['location']) : '' ?></span></div><?= $b['shoot_date'] === $today ? badge('urgent', 'Today') : badge($b['status']) ?></div>
        <?php endforeach; ?></div>
      </div>
      <div class="card">
        <div class="card-h"><h2><?= icon('layers') ?> My job queue</h2><a class="btn sm ghost" href="<?= e(url('jobs')) ?>">Open board</a></div>
        <?php if (!$myJobs): ?><p class="muted">No open jobs assigned to you.</p><?php endif; ?>
        <div class="mini-list"><?php foreach ($myJobs as $j): $late = $j['due_date'] && $j['due_date'] < $today; ?>
          <div class="mini"><div class="l"><b><?= e($j['title']) ?></b><span class="sub-line"><?= e($j['client']) ?> · <span class="<?= $late ? 'red-t' : '' ?>">due <?= e(fmt_date($j['due_date'])) ?></span></span></div><?= badge($j['status'], JOB_STATUSES[$j['status']]) ?></div>
        <?php endforeach; ?></div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* ================= Business dashboard ================= */
[$from, $to, $key] = period_current();
$seeMoney = can('expenses');
$p = [];
$cond = range_cond('s.sale_date', $from, $to, $p);
$k = row('SELECT COUNT(*) AS n, COALESCE(SUM(s.total),0) AS billed, COALESCE(SUM(COALESCE(p.paid,0)),0) AS paid ' . SALES_FROM . " WHERE s.status = 'active' $cond", $p);
$billed = (float) $k['billed'];
$salesN = (int) $k['n'];
$uncollected = max(0, $billed - (float) $k['paid']);
$aov = $salesN ? $billed / $salesN : 0;

$p2 = [];
$c2 = range_cond('DATE(p.paid_at)', $from, $to, $p2);
$collected = (float) val("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales s ON s.id = p.sale_id WHERE s.status = 'active' $c2", $p2);

$p3 = [];
$c3 = range_cond('e.expense_date', $from, $to, $p3);
$exp = row("SELECT COALESCE(SUM(e.amount),0) AS t, COUNT(*) AS n FROM expenses e WHERE 1=1 $c3", $p3);
$expenses = (float) $exp['t'];
$net = $collected - $expenses;

/* Target scaled to the selected period */
$monthTarget = (float) setting('monthly_target', '150000');
$dim = (int) date('t');
$targetMap = ['month' => $monthTarget, 'year' => $monthTarget * 12, 'today' => $monthTarget / $dim, 'week' => $monthTarget * 7 / $dim];
$target = $targetMap[$key] ?? 0.0;
$pct = $target > 0 ? min(100, $billed / $target * 100) : 0;

$trend = trend_series($from, $to);

$pp = [];
$cp = range_cond('s.sale_date', $from, $to, $pp);
$svc = rows("SELECT COALESCE(pk.name, si.description) AS label, SUM(si.line_total) AS v FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN packages pk ON pk.id = si.package_id WHERE s.status = 'active' $cp GROUP BY COALESCE(pk.name, si.description) ORDER BY v DESC", $pp);
$items = [];
$other = 0.0;
foreach ($svc as $i => $r) {
    if ($i < 6) {
        $items[] = ['label' => $r['label'], 'value' => (float) $r['v']];
    } else {
        $other += (float) $r['v'];
    }
}
if ($other > 0) {
    $items[] = ['label' => 'Other', 'value' => $other];
}

$recent = rows('SELECT s.*, c.name AS client, COALESCE(p.paid,0) AS paid, (SELECT description FROM sale_items si WHERE si.sale_id = s.id ORDER BY si.id LIMIT 1) AS first_item ' . SALES_FROM . " WHERE s.status = 'active' $cond ORDER BY s.sale_date DESC, s.id DESC LIMIT 8", $p);
$owing = rows('SELECT s.id, s.receipt_no, s.sale_date, s.total, COALESCE(p.paid,0) AS paid, c.name ' . SALES_FROM . " WHERE s.status = 'active' AND s.total - COALESCE(p.paid,0) > 0.004 ORDER BY s.sale_date ASC LIMIT 7");
$owingTotal = (float) val('SELECT COALESCE(SUM(s.total - COALESCE(p.paid,0)),0) ' . SALES_FROM . " WHERE s.status = 'active' AND s.total - COALESCE(p.paid,0) > 0.004");
$upcoming = rows("SELECT b.*, c.name AS client, u.name AS staff FROM bookings b JOIN clients c ON c.id = b.client_id LEFT JOIN users u ON u.id = b.assigned_to WHERE b.shoot_date BETWEEN ? AND ? AND b.status IN ('pending','confirmed') ORDER BY b.shoot_date, b.start_time LIMIT 7", [$today, date('Y-m-d', strtotime('+7 days'))]);
$jobCounts = array_fill_keys(['queued', 'in_progress', 'review'], 0);
foreach (rows("SELECT status, COUNT(*) AS n FROM jobs WHERE status <> 'delivered' GROUP BY status") as $r) {
    $jobCounts[$r['status']] = (int) $r['n'];
}
$jobsLate = (int) val("SELECT COUNT(*) FROM jobs WHERE status <> 'delivered' AND due_date < ?", [$today]);
$leaders = can('reports') ? rows('SELECT COALESCE(u.name, "Unassigned") AS name, COUNT(*) AS n, SUM(s.total) AS billed FROM sales s LEFT JOIN users u ON u.id = s.sold_by WHERE s.status = \'active\' ' . $cond . ' GROUP BY s.sold_by, u.name ORDER BY billed DESC LIMIT 5', $p) : [];

page_start('Dashboard', 'dashboard');
page_head($greet . ', ' . $first, date('l, d F Y') . ' · KPI & Sales Engine');
period_filter('dashboard', [], true);
?>
<div class="grid g4">
  <div class="card kpi">
    <span class="lbl">Gross revenue</span><span class="val"><?= money($billed) ?></span>
    <span class="ki"><?= icon('sales') ?></span>
    <?php if ($target > 0): ?>
      <div class="sub"><span>Target: <b><?= money($target) ?></b></span><span><?= round($pct) ?>%</span></div><div class="bar"><i style="width:<?= round($pct) ?>%"></i></div>
    <?php else: ?><div class="sub"><span>Collected: <b><?= money($collected) ?></b></span></div><div class="bar"><i style="width:<?= $billed > 0 ? round(min(100, $collected / $billed * 100)) : 0 ?>%"></i></div><?php endif; ?>
  </div>
  <?php if ($seeMoney): ?>
  <div class="card kpi red"><span class="lbl">Total expenses</span><span class="val"><?= money($expenses) ?></span><span class="ki"><?= icon('card') ?></span><div class="sub"><span>Operating costs</span><span style="color:var(--red)"><?= (int) $exp['n'] ?> entries</span></div><div class="bar"><i style="width:<?= $collected > 0 ? round(min(100, $expenses / $collected * 100)) : 0 ?>%;background:var(--red)"></i></div></div>
  <div class="card kpi hl"><span class="lbl">Net profit made</span><span class="val" style="<?= $net < 0 ? 'color:var(--red)' : '' ?>"><?= $net < 0 ? '− ' : '' ?><?= money(abs($net)) ?></span><span class="ki"><?= icon('target') ?></span><div class="sub"><span>Collected − expenses</span></div><div class="bar green"><i style="width:<?= $collected > 0 ? round(max(0, min(100, $net / $collected * 100))) : 0 ?>%"></i></div></div>
  <?php else: ?>
  <div class="card kpi green"><span class="lbl">Collected (cash in)</span><span class="val"><?= money($collected) ?></span><span class="ki"><?= icon('card') ?></span></div>
  <div class="card kpi hl"><span class="lbl">Uncollected balances</span><span class="val"><?= money($uncollected) ?></span><span class="ki"><?= icon('clock') ?></span></div>
  <?php endif; ?>
  <div class="card kpi"><span class="lbl">Total sales count</span><span class="val"><?= $salesN ?></span><span class="ki"><?= icon('camera') ?></span><div class="sub"><span>Avg. order value</span><b><?= money($aov) ?></b></div><div class="bar"><i style="width:<?= $salesN ? 100 : 0 ?>%"></i></div></div>
</div>
<?php if ($seeMoney): ?>
<div class="grid g3" style="margin-top:16px">
  <div class="card kpi green"><span class="lbl">Collected (cash in)</span><span class="val"><?= money($collected) ?></span><span class="ki"><?= icon('download') ?></span></div>
  <div class="card kpi hl"><span class="lbl">Uncollected balances (period)</span><span class="val"><?= money($uncollected) ?></span><span class="ki"><?= icon('clock') ?></span></div>
  <div class="card kpi"><span class="lbl">All-time outstanding</span><span class="val"><?= money($owingTotal) ?></span><span class="ki"><?= icon('tag') ?></span></div>
</div>
<?php endif; ?>

<div class="grid g-main">
  <div class="card">
    <div class="card-h"><h2><?= icon('chart') ?> Sales revenue trend</h2><span class="muted small">Billed vs collected</span></div>
    <div class="chart" data-type="line" data-chart='<?= chart_json(['labels' => $trend['labels'], 'prefix' => '', 'series' => [['name' => 'Billed', 'color' => '#f6b900', 'values' => $trend['billed']], ['name' => 'Collected', 'color' => '#33d6a0', 'values' => $trend['collected']]]]) ?>'></div>
  </div>
  <div class="card">
    <div class="card-h"><h2><?= icon('tag') ?> Revenue by service</h2></div>
    <div class="chart" data-type="donut" data-chart='<?= chart_json(['items' => $items, 'center' => 'billed']) ?>'></div>
  </div>
</div>

<div class="grid g-main">
  <div class="card">
    <div class="card-h"><h2><?= icon('sales') ?> Recent sales</h2><a class="btn sm ghost" href="<?= e(url('sales')) ?>">View all</a></div>
    <?php if (!$recent): ?>
      <div class="empty"><h2>No sales recorded for this timeframe</h2><a class="btn" href="<?= e(url('sale_form')) ?>">Log a sale</a></div>
    <?php else: ?>
    <div class="tbl-wrap"><table class="tbl stack">
      <thead><tr><th>Date</th><th>Client</th><th>Service</th><th class="r">Total</th><th class="r">Balance</th><th>Status</th></tr></thead>
      <tbody><?php foreach ($recent as $s): $bal = max(0, (float) $s['total'] - (float) $s['paid']); ?>
        <tr>
          <td data-label="Date"><?= e(fmt_date($s['sale_date'], 'd M')) ?></td>
          <td data-label="Client"><a href="<?= e(url('sale_view', ['id' => $s['id']])) ?>"><b><?= e($s['client']) ?></b></a></td>
          <td data-label="Service"><?= e($s['first_item']) ?></td>
          <td data-label="Total" class="r"><?= money($s['total']) ?></td>
          <td data-label="Balance" class="r <?= $bal > 0 ? 'gold-t' : '' ?>"><?= money($bal) ?></td>
          <td data-label="Status"><?= badge(pay_status($s)) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div>
    <div class="card">
      <div class="card-h"><h2><?= icon('calendar') ?> Next 7 days</h2><a class="btn sm ghost" href="<?= e(url('bookings')) ?>">Schedule</a></div>
      <?php if (!$upcoming): ?><p class="muted">No shoots booked in the next week.</p><?php endif; ?>
      <div class="mini-list"><?php foreach ($upcoming as $b): ?>
        <div class="mini"><div class="l"><b><?= e($b['client']) ?> — <?= e($b['title']) ?></b><span class="sub-line"><?= e(fmt_date($b['shoot_date'], 'D d M')) ?> <?= e(fmt_time($b['start_time'])) ?> · <?= e($b['staff'] ?: 'Unassigned') ?></span></div><?= $b['shoot_date'] === $today ? badge('urgent', 'Today') : badge($b['status']) ?></div>
      <?php endforeach; ?></div>
    </div>
    <div class="card">
      <div class="card-h"><h2><?= icon('layers') ?> Retouching queue</h2><a class="btn sm ghost" href="<?= e(url('jobs')) ?>">Board</a></div>
      <div class="mini"><span>Queued</span><b><?= $jobCounts['queued'] ?></b></div>
      <div class="mini"><span>In progress</span><b><?= $jobCounts['in_progress'] ?></b></div>
      <div class="mini"><span>In review</span><b><?= $jobCounts['review'] ?></b></div>
      <div class="mini"><span class="<?= $jobsLate ? 'red-t' : 'muted' ?>">Overdue</span><b class="<?= $jobsLate ? 'red-t' : '' ?>"><?= $jobsLate ?></b></div>
    </div>
    <?php if ($owing): ?>
    <div class="card">
      <div class="card-h"><h2><?= icon('clock') ?> Balances to collect</h2><span class="gold-t"><b><?= money($owingTotal) ?></b></span></div>
      <div class="mini-list"><?php foreach ($owing as $o): ?>
        <div class="mini"><div class="l"><b><?= e($o['name']) ?></b><span class="sub-line"><?= e($o['receipt_no']) ?> · <?= e(fmt_date($o['sale_date'], 'd M')) ?></span></div><a href="<?= e(url('sale_view', ['id' => $o['id']])) ?>"><b class="gold-t"><?= money((float) $o['total'] - (float) $o['paid']) ?></b></a></div>
      <?php endforeach; ?></div>
    </div>
    <?php endif; ?>
    <?php if ($leaders): ?>
    <div class="card">
      <div class="card-h"><h2><?= icon('users') ?> Top sellers</h2><a class="btn sm ghost" href="<?= e(url('reports')) ?>">Tracker</a></div>
      <div class="mini-list"><?php foreach ($leaders as $i => $l): ?>
        <div class="mini"><div class="l"><b><?= ($i + 1) . '. ' . e($l['name']) ?></b><span class="sub-line"><?= (int) $l['n'] ?> sale<?= (int) $l['n'] === 1 ? '' : 's' ?></span></div><b><?= money($l['billed']) ?></b></div>
      <?php endforeach; ?></div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php page_end();

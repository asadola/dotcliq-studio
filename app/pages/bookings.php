<?php
declare(strict_types=1);

require_can('bookings');
$me = (int) user()['id'];

/* Quick status change (photographers may update their own shoots) */
if (is_post() && post('action') === 'status') {
    $id = (int) post('id');
    $status = pick(post('status'), array_keys(BOOKING_STATUSES), '');
    $b = row('SELECT * FROM bookings WHERE id = ?', [$id]);
    if ($b && $status !== '' && (can('assign') || (int) $b['assigned_to'] === $me)) {
        q('UPDATE bookings SET status = ? WHERE id = ?', [$status, $id]);
        log_activity('booking_status', '#' . $id . ' → ' . $status);
        flash('Booking marked ' . strtolower(BOOKING_STATUSES[$status]) . '.');
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? url('bookings')));
    exit;
}

$view = pick(get('v', 'cal'), ['cal', 'list', 'upcoming'], 'cal');
$month = preg_match('/^\d{4}-\d{2}$/', get('m')) ? get('m') : date('Y-m');
$statusF = pick(get('status'), array_keys(BOOKING_STATUSES), '');
$staffF = (int) get('staff');
$first = $month . '-01';
$last = date('Y-m-t', strtotime($first));

$where = '1=1';
$params = [];
if ($view === 'upcoming') {
    $where .= " AND b.shoot_date >= ? AND b.status IN ('pending','confirmed')";
    $params[] = date('Y-m-d');
} else {
    $where .= ' AND b.shoot_date BETWEEN ? AND ?';
    array_push($params, $first, $last);
}
if (is_scoped()) {
    $where .= ' AND b.assigned_to = ?';
    $params[] = $me;
} elseif ($staffF > 0) {
    $where .= ' AND b.assigned_to = ?';
    $params[] = $staffF;
}
if ($statusF !== '') {
    $where .= ' AND b.status = ?';
    $params[] = $statusF;
}

$list = rows(
    "SELECT b.*, c.name AS client, c.phone, u.name AS staff, pk.name AS package
     FROM bookings b JOIN clients c ON c.id = b.client_id
     LEFT JOIN users u ON u.id = b.assigned_to LEFT JOIN packages pk ON pk.id = b.package_id
     WHERE $where ORDER BY b.shoot_date, b.start_time" . ($view === 'upcoming' ? ' LIMIT 100' : ''),
    $params
);

$byDay = [];
foreach ($list as $b) {
    $byDay[$b['shoot_date']][] = $b;
}

$prev = date('Y-m', strtotime($first . ' -1 month'));
$next = date('Y-m', strtotime($first . ' +1 month'));
$base = ['v' => $view, 'status' => $statusF, 'staff' => $staffF ?: ''];
$staff = is_scoped() ? [] : staff_list();

page_start('Bookings', 'bookings');
page_head('Bookings & Schedule', is_scoped() ? 'Your assigned shoots' : 'Plan shoots, assign photographers, track status',
    (can('assign') ? '<a class="btn" href="' . e(url('booking_form')) . '">' . icon('plus', 16) . ' New booking</a>' : ''));
?>
<div class="card filter">
  <div class="row">
    <div class="seg">
      <a class="<?= $view === 'cal' ? 'on' : '' ?>" href="<?= e(url('bookings', ['v' => 'cal', 'm' => $month])) ?>">Calendar</a>
      <a class="<?= $view === 'list' ? 'on' : '' ?>" href="<?= e(url('bookings', ['v' => 'list', 'm' => $month])) ?>">Month list</a>
      <a class="<?= $view === 'upcoming' ? 'on' : '' ?>" href="<?= e(url('bookings', ['v' => 'upcoming'])) ?>">Upcoming</a>
    </div>
    <?php if ($view !== 'upcoming'): ?>
      <a class="btn sm ghost" href="<?= e(url('bookings', $base + ['m' => $prev])) ?>">←</a>
      <b style="min-width:130px;text-align:center"><?= e(date('F Y', strtotime($first))) ?></b>
      <a class="btn sm ghost" href="<?= e(url('bookings', $base + ['m' => $next])) ?>">→</a>
      <a class="btn sm ghost" href="<?= e(url('bookings', $base + ['m' => date('Y-m')])) ?>">Today</a>
    <?php endif; ?>
  </div>
  <form method="get" action="index.php" class="row">
    <input type="hidden" name="p" value="bookings"><input type="hidden" name="v" value="<?= e($view) ?>"><input type="hidden" name="m" value="<?= e($month) ?>">
    <select name="status" onchange="this.form.submit()" aria-label="Filter by status" style="width:auto"><option value="">All statuses</option><?php foreach (BOOKING_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $statusF === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <?php if ($staff): ?><select name="staff" onchange="this.form.submit()" aria-label="Filter by staff" style="width:auto"><option value="">All staff</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $staffF === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
  </form>
</div>

<?php if ($view === 'cal'):
    $startDow = (int) date('N', strtotime($first)); // 1=Mon
    $daysIn = (int) date('t', strtotime($first));
    $cells = $startDow - 1 + $daysIn;
    $cells = (int) (ceil($cells / 7) * 7);
    $today = date('Y-m-d'); ?>
<div class="cal" role="grid" aria-label="Booking calendar">
  <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d): ?><div class="dow"><?= $d ?></div><?php endforeach; ?>
  <?php for ($i = 0; $i < $cells; $i++):
      $dayNum = $i - ($startDow - 1) + 1;
      $inMonth = $dayNum >= 1 && $dayNum <= $daysIn;
      $date = $inMonth ? $month . '-' . str_pad((string) $dayNum, 2, '0', STR_PAD_LEFT) : '';
      $evts = $inMonth ? ($byDay[$date] ?? []) : []; ?>
    <div class="day <?= $inMonth ? '' : 'out' ?> <?= $date === $today ? 'today' : '' ?>">
      <?php if ($inMonth): ?><span class="dn"><?= $dayNum ?></span><?php endif; ?>
      <?php foreach (array_slice($evts, 0, 3) as $b): ?>
        <a class="evt <?= e($b['status']) ?>" href="<?= e(url(can('assign') ? 'booking_form' : 'bookings', ['id' => $b['id'], 'v' => 'list', 'm' => $month])) ?>" title="<?= e($b['title'] . ' — ' . $b['client']) ?>"><?= e(fmt_time($b['start_time'])) ?> <?= e($b['client']) ?></a>
      <?php endforeach; ?>
      <?php if (count($evts) > 3): ?><span class="more">+<?= count($evts) - 3 ?> more</span><?php endif; ?>
    </div>
  <?php endfor; ?>
</div>
<?php endif; ?>

<?php if ($view === 'cal'): /* mobile agenda */ ?>
<div class="agenda">
  <?php if (!$byDay): ?><div class="card empty">No shoots this month.</div><?php endif; ?>
  <?php foreach ($byDay as $date => $items): ?>
    <div class="card ag-day"><h4><?= e(fmt_date($date, 'D, d M')) ?></h4>
      <?php foreach ($items as $b): ?>
        <div class="mini"><div class="l"><b><?= e($b['client']) ?> — <?= e($b['title']) ?></b><span class="sub-line"><?= e(fmt_time($b['start_time'])) ?> · <?= e($b['staff'] ?: 'Unassigned') ?></span></div><?= badge($b['status']) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card">
<?php if (!$list): ?>
  <div class="empty"><h2>No bookings found</h2><p>Nothing scheduled for this view.</p><?php if (can('assign')): ?><a class="btn" href="<?= e(url('booking_form')) ?>">Create a booking</a><?php endif; ?></div>
<?php else: ?>
  <div class="tbl-wrap"><table class="tbl stack">
    <thead><tr><th>Date & time</th><th>Booking</th><th>Client</th><th>Photographer</th><th>Location</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $b): $mine = (int) $b['assigned_to'] === $me; ?>
      <tr>
        <td data-label="When" class="nowrap"><b><?= e(fmt_date($b['shoot_date'], 'D, d M')) ?></b><span class="sub-line"><?= e(fmt_time($b['start_time'])) ?><?= $b['end_time'] ? ' – ' . e(fmt_time($b['end_time'])) : '' ?></span></td>
        <td data-label="Booking"><b><?= e($b['title']) ?></b><span class="sub-line"><?= e($b['package'] ?: '') ?></span></td>
        <td data-label="Client"><?= e($b['client']) ?><span class="sub-line"><?= e($b['phone']) ?></span></td>
        <td data-label="Staff"><?= e($b['staff'] ?: 'Unassigned') ?></td>
        <td data-label="Location"><?= e($b['location'] ?: '—') ?></td>
        <td data-label="Status"><?= badge($b['status']) ?></td>
        <td class="act">
          <?php if ($b['status'] !== 'completed' && $b['status'] !== 'cancelled' && (can('assign') || $mine)): ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><input type="hidden" name="status" value="<?= $b['status'] === 'pending' ? 'confirmed' : 'completed' ?>"><button class="btn sm ghost" type="submit"><?= icon('check', 14) ?> <?= $b['status'] === 'pending' ? 'Confirm' : 'Complete' ?></button></form>
          <?php endif; ?>
          <?php if (!$b['sale_id'] && can('sales') && $b['status'] !== 'cancelled'): ?><a class="btn sm gold" href="<?= e(url('sale_form', ['booking' => $b['id']])) ?>">Create sale</a><?php endif; ?>
          <?php if ($b['sale_id'] && can('sales')): ?><a class="btn sm ghost" href="<?= e(url('sale_view', ['id' => $b['sale_id']])) ?>">Sale</a><?php endif; ?>
          <?php if (can('assign')): ?><a class="btn sm ghost" href="<?= e(url('booking_form', ['id' => $b['id']])) ?>"><?= icon('edit', 14) ?></a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>
<?php endif; ?>
<?php page_end();

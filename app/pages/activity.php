<?php
declare(strict_types=1);

require_can('activity');

$userF = (int) get('user');
$actionF = get('action');
$where = '1=1';
$params = [];
if ($userF > 0) {
    $where .= ' AND l.user_id = ?';
    $params[] = $userF;
}
if ($actionF !== '') {
    $where .= ' AND l.action LIKE ?';
    $params[] = $actionF . '%';
}
$total = (int) val("SELECT COUNT(*) FROM activity_log l WHERE $where", $params);
$pg = paginate($total, 40);
$list = rows("SELECT l.*, u.name AS who FROM activity_log l LEFT JOIN users u ON u.id = l.user_id WHERE $where ORDER BY l.id DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
$users = rows('SELECT id, name FROM users ORDER BY name');
$groups = ['login' => 'Logins', 'sale' => 'Sales', 'payment' => 'Payments', 'booking' => 'Bookings', 'job' => 'Jobs', 'expense' => 'Expenses', 'user' => 'Users', 'export' => 'Exports'];

page_start('Activity Log', 'activity');
page_head('Activity Log', 'Who did what, and when — ' . $total . ' events');
?>
<div class="card filter">
  <form method="get" action="index.php" class="row">
    <input type="hidden" name="p" value="activity">
    <select name="user" onchange="this.form.submit()" style="width:auto" aria-label="User"><option value="">All users</option><?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $userF === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select>
    <select name="action" onchange="this.form.submit()" style="width:auto" aria-label="Type"><option value="">All activity</option><?php foreach ($groups as $k => $l): ?><option value="<?= e($k) ?>" <?= $actionF === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  </form>
</div>
<div class="card">
<?php if (!$list): ?><div class="empty"><p>No activity recorded.</p></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl stack">
    <thead><tr><th>When</th><th>User</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
    <tbody><?php foreach ($list as $r): ?>
      <tr>
        <td data-label="When" class="nowrap"><?= e(date('d M, g:i A', strtotime((string) $r['created_at']))) ?></td>
        <td data-label="User"><?= e($r['who'] ?: '—') ?></td>
        <td data-label="Action"><span class="badge b-mute"><?= e($r['action']) ?></span></td>
        <td data-label="Details"><?= e($r['detail']) ?></td>
        <td data-label="IP" class="muted small"><?= e($r['ip']) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= pager($pg, 'activity', ['user' => $userF ?: '', 'action' => $actionF]) ?>
<?php endif; ?>
</div>
<?php page_end();

<?php
declare(strict_types=1);

require_can('jobs');
$me = (int) user()['id'];
$today = date('Y-m-d');

/* Move a job between stages */
if (is_post() && post('action') === 'move') {
    $id = (int) post('id');
    $status = pick(post('status'), array_keys(JOB_STATUSES), '');
    $j = row('SELECT * FROM jobs WHERE id = ?', [$id]);
    if ($j && $status !== '' && (can('assign') || (int) $j['assigned_to'] === $me)) {
        q('UPDATE jobs SET status = ?, delivered_at = ? WHERE id = ?', [$status, $status === 'delivered' ? date('Y-m-d H:i:s') : null, $id]);
        log_activity('job_move', '#' . $id . ' → ' . $status);
        flash('"' . $j['title'] . '" moved to ' . JOB_STATUSES[$status] . '.');
        if ($status === 'delivered' && $j['sale_id'] && can('sales')) {
            $s = sale_totals((int) $j['sale_id']);
            if ($s && $s['status'] === 'active' && (float) $s['total'] - (float) $s['paid'] > 0.004) {
                flash('Heads up: this client still owes ' . money((float) $s['total'] - (float) $s['paid']) . ' on ' . $s['receipt_no'] . '.', 'warn');
            }
        }
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? url('jobs')));
    exit;
}

$view = pick(get('v', 'board'), ['board', 'list'], 'board');
$staffF = (int) get('staff');
$showAll = get('all') === '1';

$where = '1=1';
$params = [];
if (is_scoped()) {
    $where .= ' AND j.assigned_to = ?';
    $params[] = $me;
} elseif ($staffF > 0) {
    $where .= ' AND j.assigned_to = ?';
    $params[] = $staffF;
}
if (!$showAll) {
    $where .= " AND (j.status <> 'delivered' OR j.delivered_at >= ?)";
    $params[] = date('Y-m-d H:i:s', strtotime('-14 days'));
}
$jobs = rows(
    "SELECT j.*, c.name AS client, u.name AS editor
     FROM jobs j JOIN clients c ON c.id = j.client_id LEFT JOIN users u ON u.id = j.assigned_to
     WHERE $where ORDER BY (j.priority = 'urgent') DESC, j.due_date IS NULL, j.due_date, j.id DESC LIMIT 400",
    $params
);
$cols = array_fill_keys(array_keys(JOB_STATUSES), []);
$late = 0;
foreach ($jobs as $j) {
    $cols[$j['status']][] = $j;
    if ($j['status'] !== 'delivered' && $j['due_date'] && $j['due_date'] < $today) {
        $late++;
    }
}
$staff = is_scoped() ? [] : staff_list();
$flow = ['queued' => ['in_progress', 'Start'], 'in_progress' => ['review', 'To review'], 'review' => ['delivered', 'Deliver']];
$back = ['in_progress' => 'queued', 'review' => 'in_progress', 'delivered' => 'review'];

function job_move_form(array $j, string $to, string $label, bool $primary = true): string
{
    return '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="move"><input type="hidden" name="id" value="' . (int) $j['id'] . '"><input type="hidden" name="status" value="' . e($to) . '">'
        . '<button class="btn sm ' . ($primary ? 'gold' : 'ghost') . '" type="submit">' . e($label) . '</button></form>';
}

page_start('Editing & Delivery', 'jobs');
page_head('Editing & Delivery', ($late ? $late . ' overdue · ' : '') . count($jobs) . ' jobs shown',
    '<div class="seg"><a class="' . ($view === 'board' ? 'on' : '') . '" href="' . e(url('jobs', ['v' => 'board', 'staff' => $staffF ?: '', 'all' => $showAll ? 1 : ''])) . '">Board</a><a class="' . ($view === 'list' ? 'on' : '') . '" href="' . e(url('jobs', ['v' => 'list', 'staff' => $staffF ?: '', 'all' => $showAll ? 1 : ''])) . '">List</a></div>'
    . (can('assign') ? '<a class="btn" href="' . e(url('job_form')) . '">' . icon('plus', 16) . ' Add job</a>' : ''));
?>
<div class="card filter">
  <form method="get" action="index.php" class="row">
    <input type="hidden" name="p" value="jobs"><input type="hidden" name="v" value="<?= e($view) ?>">
    <?php if ($staff): ?><select name="staff" onchange="this.form.submit()" style="width:auto" aria-label="Assignee"><option value="">All editors</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $staffF === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <label class="check"><input type="checkbox" name="all" value="1" <?= $showAll ? 'checked' : '' ?> onchange="this.form.submit()"> Include older delivered jobs</label>
  </form>
  <span class="muted small">Delivered jobs stay on the board for 14 days.</span>
</div>

<?php if (!$jobs): ?>
  <div class="card empty"><h2>No jobs in the queue</h2><p>Jobs appear here when a sale is added to the editing queue, or when you add one manually.</p><?php if (can('assign')): ?><a class="btn" href="<?= e(url('job_form')) ?>">Add a job</a><?php endif; ?></div>
<?php elseif ($view === 'board'): ?>
<div class="kanban">
  <?php foreach (JOB_STATUSES as $sk => $sl): ?>
  <section class="col" aria-label="<?= e($sl) ?>">
    <div class="col-h"><span><?= e($sl) ?></span><span class="n"><?= count($cols[$sk]) ?></span></div>
    <?php foreach ($cols[$sk] as $j): $isLate = $sk !== 'delivered' && $j['due_date'] && $j['due_date'] < $today; $mine = (int) $j['assigned_to'] === $me; ?>
      <article class="jcard <?= $j['priority'] === 'urgent' ? 'urgent' : '' ?> <?= $isLate ? 'late' : '' ?>">
        <h4><?= e($j['title']) ?></h4>
        <div class="meta"><span><?= e($j['client']) ?></span><span>·</span><span><?= e(JOB_TYPES[$j['job_type']] ?? $j['job_type']) ?></span></div>
        <div class="meta">
          <?php if ($j['priority'] === 'urgent'): ?><?= badge('urgent') ?><?php endif; ?>
          <span class="due"><?= icon('clock', 13) ?> <?= $j['due_date'] ? e(fmt_date($j['due_date'], 'd M')) . ($isLate ? ' (overdue)' : '') : 'No due date' ?></span>
          <span><?= e($j['editor'] ?: 'Unassigned') ?></span>
        </div>
        <?php if ($j['delivery_link']): ?><div class="small"><a href="<?= e($j['delivery_link']) ?>" target="_blank" rel="noopener noreferrer">Delivery link ↗</a></div><?php endif; ?>
        <div class="jmove">
          <?php if ((can('assign') || $mine) && isset($flow[$sk])) echo job_move_form($j, $flow[$sk][0], $flow[$sk][1]); ?>
          <?php if ((can('assign') || $mine) && isset($back[$sk])) echo job_move_form($j, $back[$sk], '←', false); ?>
          <?php if (can('assign') || $mine): ?><a class="btn sm ghost" href="<?= e(url('job_form', ['id' => $j['id']])) ?>"><?= icon('edit', 13) ?></a><?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$cols[$sk]): ?><p class="muted small center" style="padding:14px 0">Nothing here</p><?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card"><div class="tbl-wrap"><table class="tbl stack">
  <thead><tr><th>Job</th><th>Client</th><th>Type</th><th>Assigned</th><th>Due</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($jobs as $j): $isLate = $j['status'] !== 'delivered' && $j['due_date'] && $j['due_date'] < $today; $mine = (int) $j['assigned_to'] === $me; ?>
    <tr>
      <td data-label="Job"><b><?= e($j['title']) ?></b> <?= $j['priority'] === 'urgent' ? badge('urgent') : '' ?></td>
      <td data-label="Client"><?= e($j['client']) ?></td>
      <td data-label="Type"><?= e(JOB_TYPES[$j['job_type']] ?? '') ?></td>
      <td data-label="Assigned"><?= e($j['editor'] ?: 'Unassigned') ?></td>
      <td data-label="Due" class="<?= $isLate ? 'red-t' : '' ?>"><?= e(fmt_date($j['due_date'])) ?></td>
      <td data-label="Status"><?= badge($j['status'], JOB_STATUSES[$j['status']]) ?></td>
      <td class="act">
        <?php if ((can('assign') || $mine) && isset($flow[$j['status']])) echo job_move_form($j, $flow[$j['status']][0], $flow[$j['status']][1]); ?>
        <?php if (can('assign') || $mine): ?><a class="btn sm ghost" href="<?= e(url('job_form', ['id' => $j['id']])) ?>"><?= icon('edit', 13) ?></a><?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div></div>
<?php endif; ?>
<?php page_end();

<?php
declare(strict_types=1);

require_can('clients');

if (is_post() && post('action') === 'add') {
    $name = post('name');
    if ($name === '') {
        flash('Client name is required.', 'err');
    } else {
        [$id, $existing] = find_or_create_client($name, post('phone'), post('email'), post('notes'));
        if ($existing) {
            flash('A client with that phone number already exists — opened their profile.', 'warn');
        } else {
            log_activity('client_add', $name);
            flash('Client added.');
        }
        redirect('client_view', ['id' => $id]);
    }
    redirect('clients');
}

$search = get('q');
$params = [];
$where = '1=1';
if ($search !== '') {
    $like = '%' . $search . '%';
    $where = '(c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)';
    $params = [$like, $like, $like];
}
$total = (int) val("SELECT COUNT(*) FROM clients c WHERE $where", $params);
$pg = paginate($total, 25);
$list = rows(
    "SELECT c.*, COUNT(s.id) AS sales_n, COALESCE(SUM(s.total), 0) AS billed, MAX(s.sale_date) AS last_visit
     FROM clients c LEFT JOIN sales s ON s.client_id = c.id AND s.status = 'active'
     WHERE $where GROUP BY c.id ORDER BY c.name LIMIT {$pg['per']} OFFSET {$pg['offset']}",
    $params
);

page_start('Clients', 'clients');
page_head('Clients', $total . ' client' . ($total === 1 ? '' : 's') . ' on record',
    '<form class="search" method="get" action="index.php"><input type="hidden" name="p" value="clients">' . icon('search', 16) . '<input name="q" value="' . e($search) . '" placeholder="Search name, phone, email"></form>'
    . '<button class="btn" data-open="addClient">' . icon('plus', 16) . ' Add client</button>');
?>
<div class="card">
<?php if (!$list): ?>
  <div class="empty"><h2>No clients yet</h2><p>Clients are created automatically when you log a sale, or add one here.</p></div>
<?php else: ?>
  <div class="tbl-wrap"><table class="tbl stack">
    <thead><tr><th>Client</th><th>Phone</th><th>Email</th><th class="r">Visits</th><th class="r">Total billed</th><th>Last visit</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $c): ?>
      <tr>
        <td data-label="Client"><a href="<?= e(url('client_view', ['id' => $c['id']])) ?>"><b><?= e($c['name']) ?></b></a></td>
        <td data-label="Phone"><?= e($c['phone'] ?: '—') ?></td>
        <td data-label="Email"><?= e($c['email'] ?: '—') ?></td>
        <td data-label="Visits" class="r"><?= (int) $c['sales_n'] ?></td>
        <td data-label="Billed" class="r"><?= money($c['billed']) ?></td>
        <td data-label="Last visit"><?= e(fmt_date($c['last_visit'])) ?></td>
        <td class="act"><a class="btn sm ghost" href="<?= e(url('client_view', ['id' => $c['id']])) ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager($pg, 'clients', ['q' => $search]) ?>
<?php endif; ?>
</div>

<dialog id="addClient">
  <div class="dlg-h"><h2>Add client</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x', 18) ?></button></div>
  <form method="post" class="dlg-b">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <label class="fld"><span>Full name *</span><input name="name" required maxlength="140"></label>
    <div class="grid2">
      <label class="fld"><span>Phone</span><input name="phone" inputmode="tel" maxlength="40" placeholder="07XX XXX XXX"></label>
      <label class="fld"><span>Email</span><input type="email" name="email" maxlength="160"></label>
    </div>
    <label class="fld"><span>Notes</span><textarea name="notes" placeholder="Preferences, how they found us…"></textarea></label>
    <div class="form-actions"><button class="btn" type="submit">Save client</button><button class="btn ghost" type="button" data-close>Cancel</button></div>
  </form>
</dialog>
<?php page_end();

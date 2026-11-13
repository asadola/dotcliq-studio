<?php
declare(strict_types=1);

require_can('users');
$me = (int) user()['id'];

function other_active_admins(int $exceptId): int
{
    return (int) val("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", [$exceptId]);
}

if (is_post() && post('action') === 'save') {
    $id = (int) post('id');
    $name = post('name');
    $username = post('username');
    $email = post('email');
    $role = pick(post('role'), array_keys(ROLES), 'frontdesk');
    $active = post('active') === '1' ? 1 : 0;
    $pass = (string) ($_POST['password'] ?? '');
    $errors = [];

    if ($name === '') $errors[] = 'Enter the full name.';
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $username)) $errors[] = 'Username must be 3–40 characters (letters, numbers, . _ -).';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is not valid.';
    if ((int) val('SELECT COUNT(*) FROM users WHERE username = ? AND id <> ?', [$username, $id]) > 0) $errors[] = 'That username is already taken.';
    if ($id === 0 && strlen($pass) < 8) $errors[] = 'Set a password of at least 8 characters.';
    if ($id > 0 && $pass !== '' && strlen($pass) < 8) $errors[] = 'New password must be at least 8 characters.';

    if ($id > 0) {
        $existing = row('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$existing) {
            $errors[] = 'User not found.';
        } elseif ($id === $me && ($role !== 'admin' || !$active)) {
            $errors[] = 'You cannot remove your own admin access or deactivate yourself.';
        } elseif ($existing['role'] === 'admin' && ($role !== 'admin' || !$active) && other_active_admins($id) === 0) {
            $errors[] = 'There must be at least one active administrator.';
        }
    }

    if ($errors) {
        foreach ($errors as $er) flash($er, 'err');
    } elseif ($id > 0) {
        q('UPDATE users SET name=?, username=?, email=?, phone=?, role=?, active=? WHERE id=?', [mb_substr($name, 0, 120), $username, $email ?: null, mb_substr(post('phone'), 0, 40) ?: null, $role, $active, $id]);
        if ($pass !== '') {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
        }
        log_activity('user_edit', $username . ' (' . $role . ')' . ($pass !== '' ? ' + password reset' : ''));
        flash('User updated.');
    } else {
        q('INSERT INTO users (name, username, email, phone, password_hash, role, active, created_at) VALUES (?,?,?,?,?,?,?,?)',
            [mb_substr($name, 0, 120), $username, $email ?: null, mb_substr(post('phone'), 0, 40) ?: null, password_hash($pass, PASSWORD_DEFAULT), $role, $active, date('Y-m-d H:i:s')]);
        log_activity('user_add', $username . ' (' . $role . ')');
        flash('User created. Share the username and password with them securely.');
    }
    redirect('users');
}

$list = rows('SELECT u.*, (SELECT COUNT(*) FROM sales s WHERE s.sold_by = u.id) AS sales_n FROM users u ORDER BY u.active DESC, u.role, u.name');

page_start('Team & Users', 'users');
page_head('Team & Users', count($list) . ' account' . (count($list) === 1 ? '' : 's') . ' · roles control what each person can see and do',
    '<button class="btn" data-open="userDlg" data-fill=\'' . e(json_encode(['id' => '', 'name' => '', 'username' => '', 'email' => '', 'phone' => '', 'role' => 'frontdesk', 'active' => 1, 'password' => '', '_title' => 'Add team member'])) . '\'>' . icon('plus', 16) . ' Add user</button>');
?>
<div class="card">
  <div class="tbl-wrap"><table class="tbl stack">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last login</th><th class="r">Sales</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
      <tr>
        <td data-label="Name"><b><?= e($r['name']) ?></b><span class="sub-line"><?= e($r['email'] ?: $r['phone']) ?></span></td>
        <td data-label="Username"><?= e($r['username']) ?></td>
        <td data-label="Role"><?= e(role_label($r['role'])) ?></td>
        <td data-label="Last login"><?= $r['last_login'] ? e(fmt_date(substr((string) $r['last_login'], 0, 10), 'd M, ') . date('g:i A', strtotime($r['last_login']))) : 'Never' ?></td>
        <td data-label="Sales" class="r"><?= (int) $r['sales_n'] ?></td>
        <td data-label="Status"><?= badge($r['active'] ? 'active' : 'inactive') ?></td>
        <td class="act"><button class="btn sm ghost" data-open="userDlg" data-fill='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'username' => $r['username'], 'email' => $r['email'], 'phone' => $r['phone'], 'role' => $r['role'], 'active' => $r['active'], 'password' => '', '_title' => 'Edit ' . $r['name']])) ?>'><?= icon('edit', 14) ?> Edit</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <div class="card-h"><h2><?= icon('shield') ?> What each role can do</h2></div>
  <div class="tbl-wrap"><table class="tbl stack">
    <thead><tr><th>Role</th><th>Access</th></tr></thead>
    <tbody>
      <tr><td data-label="Role"><b>Administrator</b></td><td data-label="Access">Everything: users, settings, activity log, reports, void sales and payments.</td></tr>
      <tr><td data-label="Role"><b>Manager</b></td><td data-label="Access">Sales, payments, clients, bookings, jobs, expenses, equipment, packages and all reports. Can void/delete records.</td></tr>
      <tr><td data-label="Role"><b>Front Desk</b></td><td data-label="Access">Log sales, record payments, manage clients, schedule bookings and assign jobs. No expenses, reports or profit figures.</td></tr>
      <tr><td data-label="Role"><b>Photographer / Videographer</b></td><td data-label="Access">Sees only their own shoots and jobs; can mark shoots completed. No money.</td></tr>
      <tr><td data-label="Role"><b>Editor / Retoucher</b></td><td data-label="Access">Sees only their own editing jobs; moves them through stages and adds the delivery link. No money.</td></tr>
    </tbody>
  </table></div>
</div>

<dialog id="userDlg">
  <div class="dlg-h"><h2 data-title>User</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x', 18) ?></button></div>
  <form method="post" class="dlg-b" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <div class="grid2">
      <label class="fld"><span>Full name *</span><input name="name" required maxlength="120"></label>
      <label class="fld"><span>Username *</span><input name="username" required maxlength="40" autocapitalize="none"></label>
      <label class="fld"><span>Email</span><input type="email" name="email" maxlength="160"></label>
      <label class="fld"><span>Phone</span><input name="phone" maxlength="40" inputmode="tel"></label>
      <label class="fld"><span>Role *</span><select name="role"><?php foreach (ROLES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="fld"><span>Password <small class="muted">(leave blank to keep)</small></span><input type="password" name="password" minlength="8" autocomplete="new-password"></label>
    </div>
    <label class="check"><input type="checkbox" name="active" value="1" checked> Account active (can log in)</label>
    <div class="form-actions"><button class="btn" type="submit">Save user</button><button class="btn ghost" type="button" data-close>Cancel</button></div>
  </form>
</dialog>
<?php page_end();

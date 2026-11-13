<?php
declare(strict_types=1);

$me = (int) user()['id'];
$row = row('SELECT * FROM users WHERE id = ?', [$me]);

if (is_post()) {
    $action = post('action');
    if ($action === 'profile') {
        $name = post('name');
        $email = post('email');
        if ($name === '') {
            flash('Name is required.', 'err');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('Email address is not valid.', 'err');
        } else {
            q('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?', [mb_substr($name, 0, 120), $email ?: null, mb_substr(post('phone'), 0, 40) ?: null, $me]);
            log_activity('profile', 'Profile updated');
            flash('Profile updated.');
        }
    } elseif ($action === 'password') {
        $cur = (string) ($_POST['current'] ?? '');
        $new = (string) ($_POST['new'] ?? '');
        $new2 = (string) ($_POST['new2'] ?? '');
        if (!password_verify($cur, $row['password_hash'])) {
            flash('Your current password is incorrect.', 'err');
        } elseif (strlen($new) < 8) {
            flash('New password must be at least 8 characters.', 'err');
        } elseif ($new !== $new2) {
            flash('The new passwords do not match.', 'err');
        } else {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me]);
            session_regenerate_id(true);
            log_activity('password', 'Password changed');
            flash('Password changed.');
        }
    }
    redirect('profile');
}

page_start('My profile', '');
page_head('My profile', role_label($row['role']) . ' · username: ' . $row['username']);
?>
<div class="grid g2">
  <form method="post" class="card stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <div class="card-h"><h2><?= icon('users') ?> Details</h2></div>
    <label class="fld"><span>Full name</span><input name="name" value="<?= e($row['name']) ?>" required maxlength="120"></label>
    <label class="fld"><span>Email</span><input type="email" name="email" value="<?= e($row['email']) ?>" maxlength="160"></label>
    <label class="fld"><span>Phone</span><input name="phone" value="<?= e($row['phone']) ?>" maxlength="40" inputmode="tel"></label>
    <div class="form-actions"><button class="btn" type="submit">Save details</button></div>
  </form>
  <form method="post" class="card stack" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="card-h"><h2><?= icon('shield') ?> Change password</h2></div>
    <label class="fld"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>
    <label class="fld"><span>New password (min 8 characters)</span><input type="password" name="new" required minlength="8" autocomplete="new-password"></label>
    <label class="fld"><span>Confirm new password</span><input type="password" name="new2" required minlength="8" autocomplete="new-password"></label>
    <div class="form-actions"><button class="btn gold" type="submit">Update password</button></div>
  </form>
</div>
<?php page_end();

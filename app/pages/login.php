<?php
declare(strict_types=1);

if (user()) {
    redirect('dashboard');
}

$error = '';
$username = '';

if (is_post()) {
    $username = mb_substr(post('username'), 0, 60);
    $password = (string) ($_POST['password'] ?? '');

    if (login_blocked($username)) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $u = row('SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1', [$username, $username]);
        if ($u && (int) $u['active'] === 1 && password_verify($password, $u['password_hash'])) {
            login_user($u);
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
            }
            $_SESSION['uid'] = (int) $u['id'];
            log_activity('login', $u['username'] . ' signed in');
            redirect('dashboard');
        }
        login_failed($username);
        if ($u) {
            $error = (int) $u['active'] === 1 ? 'Incorrect username or password.' : 'This account has been deactivated. Contact the administrator.';
        } else {
            $error = 'Incorrect username or password.';
        }
    }
}
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#14030b">
<meta name="robots" content="noindex,nofollow">
<title>Sign in · <?= e(setting('studio_name', 'DotCliq Studio')) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='10' fill='%23a31f37'/%3E%3Cpath d='M29 13a11 11 0 1 0 0 14' fill='none' stroke='white' stroke-width='5' stroke-linecap='round'/%3E%3C/svg%3E">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
<script>try{var t=localStorage.getItem('dc-theme');if(t)document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
</head>
<body class="auth">
<main class="auth-card">
  <div class="auth-logo"><?= logo_mark() ?></div>
  <h1 class="auth-brand"><?= brand_html() ?></h1>
  <p class="muted center">Management Portal &amp; Back-End System</p>

  <?php foreach (take_flash() as $f): ?><div class="alert a-<?= e($f['t']) ?>" role="status"><?= e($f['m']) ?></div><?php endforeach; ?>
  <?php if ($error): ?><div class="alert a-err" role="alert"><?= e($error) ?></div><?php endif; ?>

  <form method="post" autocomplete="on" class="stack">
    <?= csrf_field() ?>
    <label class="fld"><span>Username or email</span>
      <input type="text" name="username" value="<?= e($username) ?>" placeholder="Enter username" autocomplete="username" required autofocus>
    </label>
    <label class="fld"><span>Password</span>
      <span class="pw"><input type="password" name="password" id="pw" placeholder="••••••••" autocomplete="current-password" required>
      <button type="button" class="pw-t" data-toggle-pw="#pw" aria-label="Show password">Show</button></span>
    </label>
    <button class="btn lg block gold" type="submit">Log in to Studio Engine</button>
  </form>
  <p class="muted center small">Authorised staff only. All activity is logged.</p>
</main>
<script src="assets/js/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>

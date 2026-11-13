<?php
declare(strict_types=1);

/**
 * DotCliq Studio — one-time web installer.
 * Upload the project, create an empty MySQL database in cPanel, open /install.php.
 * DELETE this file after a successful install.
 */

date_default_timezone_set('Africa/Nairobi');
$root = __DIR__;
$lock = $root . '/storage/installed.lock';
$configFile = $root . '/config.php';

function h($v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

session_start();
if (empty($_SESSION['inst'])) {
    $_SESSION['inst'] = bin2hex(random_bytes(16));
}

$done = false;
$errors = [];
$v = [
    'db_host' => 'localhost', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
    'studio' => 'DotCliq Studio', 'admin_name' => '', 'admin_user' => 'admin', 'admin_email' => '',
];

if (is_file($lock)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><body style="font-family:system-ui;background:#14030b;color:#fbeff2;display:grid;place-items:center;min-height:100vh;margin:0"><div style="text-align:center;padding:24px"><h2>Already installed</h2><p style="color:#b69aa3">Delete <code>install.php</code> from your server, then <a style="color:#f6b900" href="index.php">open the system</a>.</p></div>';
    exit;
}

$checks = [
    'PHP 8.0 or newer' => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO MySQL extension' => extension_loaded('pdo_mysql'),
    'mbstring extension' => extension_loaded('mbstring'),
    'Project folder is writable (to create config.php)' => is_writable($root),
    'storage/ folder is writable' => is_writable($root . '/storage'),
];
$ready = !in_array(false, $checks, true);

if ($ready && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($_SESSION['inst'], (string) ($_POST['_t'] ?? ''))) {
        $errors[] = 'Session expired. Refresh and try again.';
    }
    foreach ($v as $k => $_) {
        $v[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    $pass1 = (string) ($_POST['admin_pass'] ?? '');
    $pass2 = (string) ($_POST['admin_pass2'] ?? '');

    if ($v['db_name'] === '' || $v['db_user'] === '') $errors[] = 'Database name and user are required.';
    if ($v['admin_name'] === '') $errors[] = 'Enter the administrator\'s full name.';
    if (!preg_match('/^[A-Za-z0-9._-]{3,40}$/', $v['admin_user'])) $errors[] = 'Username must be 3–40 characters (letters, numbers, . _ -).';
    if ($v['admin_email'] !== '' && !filter_var($v['admin_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Admin email is not valid.';
    if (strlen($pass1) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pass1 !== $pass2) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        try {
            $pdo = new PDO(
                'mysql:host=' . $v['db_host'] . ';dbname=' . $v['db_name'] . ';charset=utf8mb4',
                $v['db_user'], $v['db_pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $pdo->exec("SET time_zone = '+03:00'");

            $exists = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'users'")->fetchColumn();
            if ($exists) {
                $errors[] = 'This database already contains DotCliq tables. Use an empty database.';
            } else {
                $sql = (string) file_get_contents($root . '/database/schema.sql');
                $sql = preg_replace('/^\s*--.*$/m', '', $sql);
                foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
                    $pdo->exec($stmt);
                }

                $now = date('Y-m-d H:i:s');
                $pdo->prepare('INSERT INTO users (name, username, email, password_hash, role, active, created_at) VALUES (?,?,?,?,?,1,?)')
                    ->execute([$v['admin_name'], $v['admin_user'], $v['admin_email'] ?: null, password_hash($pass1, PASSWORD_DEFAULT), 'admin', $now]);

                $settings = [
                    'studio_name' => $v['studio'] ?: 'DotCliq Studio',
                    'brand_tag' => 'Dream it, We Create it',
                    'currency' => 'KSh',
                    'monthly_target' => '150000',
                    'receipt_prefix' => 'DCS-',
                    'phone' => '', 'email' => '', 'address' => '',
                    'receipt_footer' => 'Thank you for choosing us. Balances are payable on or before delivery.',
                ];
                $ins = $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?)');
                foreach ($settings as $k => $val) $ins->execute([$k, $val]);

                // Sample packages — edit prices in Packages after install
                $pk = $pdo->prepare('INSERT INTO packages (name, category, price, description, active, created_at) VALUES (?,?,?,?,1,?)');
                foreach ([
                    ['Passport / ID Photos', 'photo', 300, 'Set of printed passport photos'],
                    ['Studio Portrait Session', 'photo', 3000, '30-minute studio session, edited images'],
                    ['Birthday Shoot', 'photo', 8000, 'Studio or outdoor birthday session'],
                    ['Corporate Headshots', 'photo', 4000, 'Professional headshot, retouched'],
                    ['Video Coverage (half day)', 'video', 25000, 'Event video coverage with edit'],
                    ['Wedding Photography', 'photo', 60000, 'Full-day wedding photo coverage'],
                    ['Photo + Video Combo', 'combo', 80000, 'Photography and videography bundle'],
                    ['Photo Album / Prints', 'other', 5000, 'Designed album or large prints'],
                ] as $p) $pk->execute([$p[0], $p[1], $p[2], $p[3], $now]);

                $cfg = "<?php\n// Generated by install.php on " . date('c') . "\n"
                    . "define('DB_HOST', " . var_export($v['db_host'], true) . ");\n"
                    . "define('DB_NAME', " . var_export($v['db_name'], true) . ");\n"
                    . "define('DB_USER', " . var_export($v['db_user'], true) . ");\n"
                    . "define('DB_PASS', " . var_export($v['db_pass'], true) . ");\n"
                    . "define('APP_DEBUG', false);\n";
                if (file_put_contents($configFile, $cfg, LOCK_EX) === false) {
                    throw new RuntimeException('Could not write config.php. Check folder permissions.');
                }
                @chmod($configFile, 0640);
                file_put_contents($lock, 'installed ' . date('c'));
                $done = true;
            }
        } catch (Throwable $e) {
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Install · DotCliq Studio</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="auth">
<main class="auth-card wide">
  <h1 class="auth-brand"><span class="bn">DOTCLIQ</span> <span class="bn gold">STUDIO</span></h1>
  <p class="muted center">One-time setup</p>

<?php if ($done): ?>
  <div class="alert a-ok">Installation complete. Your database is ready and the administrator account has been created.</div>
  <ol class="muted">
    <li><b>Delete <code>install.php</code></b> from your server now.</li>
    <li>Enable the HTTPS redirect in <code>.htaccess</code> once SSL is active.</li>
    <li>Log in, then add your team under <b>Team &amp; Users</b> and review <b>Packages</b> and <b>Settings</b>.</li>
  </ol>
  <a class="btn lg block gold" href="index.php">Go to login</a>
<?php else: ?>
  <ul class="checks">
    <?php foreach ($checks as $label => $ok): ?><li class="<?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✗' ?> <?= h($label) ?></li><?php endforeach; ?>
  </ul>
  <?php foreach ($errors as $err): ?><div class="alert a-err"><?= h($err) ?></div><?php endforeach; ?>
  <?php if ($ready): ?>
  <form method="post" class="stack" autocomplete="off">
    <input type="hidden" name="_t" value="<?= h($_SESSION['inst']) ?>">
    <h3>Database (create an empty one in cPanel → MySQL Databases)</h3>
    <div class="grid2">
      <label class="fld"><span>Host</span><input name="db_host" value="<?= h($v['db_host']) ?>" required></label>
      <label class="fld"><span>Database name</span><input name="db_name" value="<?= h($v['db_name']) ?>" placeholder="cpuser_dotcliq" required></label>
      <label class="fld"><span>Database user</span><input name="db_user" value="<?= h($v['db_user']) ?>" placeholder="cpuser_dcuser" required></label>
      <label class="fld"><span>Database password</span><input type="password" name="db_pass" value=""></label>
    </div>
    <h3>Studio &amp; administrator</h3>
    <div class="grid2">
      <label class="fld"><span>Studio name</span><input name="studio" value="<?= h($v['studio']) ?>" required></label>
      <label class="fld"><span>Admin full name</span><input name="admin_name" value="<?= h($v['admin_name']) ?>" required></label>
      <label class="fld"><span>Admin username</span><input name="admin_user" value="<?= h($v['admin_user']) ?>" required></label>
      <label class="fld"><span>Admin email (optional)</span><input type="email" name="admin_email" value="<?= h($v['admin_email']) ?>"></label>
      <label class="fld"><span>Password (min 8 characters)</span><input type="password" name="admin_pass" required minlength="8"></label>
      <label class="fld"><span>Confirm password</span><input type="password" name="admin_pass2" required minlength="8"></label>
    </div>
    <button class="btn lg block gold" type="submit">Install system</button>
  </form>
  <?php else: ?>
    <div class="alert a-err">Fix the failed checks above (ask Namecheap support or switch PHP version in cPanel → Select PHP Version), then refresh.</div>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>

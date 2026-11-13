<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$page = preg_replace('/[^a-z_]/', '', (string) ($_GET['p'] ?? 'dashboard')) ?: 'dashboard';
$file = ROOT . '/app/pages/' . $page . '.php';

if (!is_file($file)) {
    http_response_code(404);
    if (user()) {
        page_start('Not found', '');
        echo '<div class="card empty"><h2>Page not found</h2><a class="btn" href="' . e(url('dashboard')) . '">Back to dashboard</a></div>';
        page_end();
    } else {
        redirect('login');
    }
    exit;
}

if (is_post()) {
    csrf_verify();
}

if ($page !== 'login') {
    require_login();
}

require $file;

<?php
declare(strict_types=1);

if (is_post()) {
    log_activity('logout', 'Signed out');
    $_SESSION = [];
    session_regenerate_id(true); // new session id, old one is destroyed server-side
    flash('Logged out successfully.');
}
redirect('login');

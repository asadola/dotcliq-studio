<?php
declare(strict_types=1);

const ROLES = [
    'admin'        => 'Administrator',
    'manager'      => 'Manager',
    'frontdesk'    => 'Front Desk',
    'photographer' => 'Photographer / Videographer',
    'editor'       => 'Editor / Retoucher',
];

/**
 * Permission map. "scoped" roles only see records assigned to them
 * (bookings and jobs) and never see money.
 */
const ROLE_PERMS = [
    'admin'        => ['*'],
    'manager'      => ['dashboard', 'clients', 'packages', 'bookings', 'sales', 'payments', 'jobs', 'expenses', 'equipment', 'reports', 'void', 'assign'],
    'frontdesk'    => ['dashboard', 'clients', 'bookings', 'sales', 'payments', 'jobs', 'assign'],
    'photographer' => ['dashboard', 'bookings', 'jobs'],
    'editor'       => ['dashboard', 'jobs'],
];

function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $r = row('SELECT id, name, username, role, active FROM users WHERE id = ?', [(int) $_SESSION['uid']]);
            if ($r && (int) $r['active'] === 1) {
                $u = $r;
            } else {
                unset($_SESSION['uid']);
            }
        }
    }
    return $u;
}

function require_login(): void
{
    if (!user()) {
        redirect('login');
    }
}

function can(string $perm): bool
{
    $u = user();
    if (!$u) {
        return false;
    }
    $perms = ROLE_PERMS[$u['role']] ?? [];
    return in_array('*', $perms, true) || in_array($perm, $perms, true);
}

function require_can(string $perm): void
{
    if (!can($perm)) {
        http_response_code(403);
        page_start('Access denied', '');
        echo '<div class="card empty"><h2>Access denied</h2><p class="muted">Your role does not have permission to open this page.</p><a class="btn" href="' . e(url('dashboard')) . '">Back to dashboard</a></div>';
        page_end();
        exit;
    }
}

/** Photographers and editors only work with their own assignments. */
function is_scoped(): bool
{
    $u = user();
    return $u && in_array($u['role'], ['photographer', 'editor'], true);
}

function is_admin(): bool
{
    $u = user();
    return $u && $u['role'] === 'admin';
}

function role_label(string $role): string
{
    return ROLES[$role] ?? ucfirst($role);
}

/* ---------- Login throttling ---------- */

function login_blocked(string $username): bool
{
    $cut = date('Y-m-d H:i:s', time() - 900);
    $byIp = (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at > ?', [client_ip(), $cut]);
    $byUser = $username !== '' ? (int) val('SELECT COUNT(*) FROM login_attempts WHERE username = ? AND created_at > ?', [$username, $cut]) : 0;
    return $byIp >= 10 || $byUser >= 6;
}

function login_failed(string $username): void
{
    q('INSERT INTO login_attempts (ip, username, created_at) VALUES (?,?,?)', [client_ip(), mb_substr($username, 0, 60), date('Y-m-d H:i:s')]);
    q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
}

function login_user(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    q('DELETE FROM login_attempts WHERE ip = ? OR username = ?', [client_ip(), $u['username']]);
    q('UPDATE users SET last_login = ? WHERE id = ?', [date('Y-m-d H:i:s'), $u['id']]);
}

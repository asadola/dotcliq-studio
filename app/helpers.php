<?php
declare(strict_types=1);

/* ---------- Output & formatting ---------- */

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($n, bool $symbol = true): string
{
    $n = (float) $n;
    $dec = abs($n - round($n)) > 0.004 ? 2 : 0;
    $s = number_format($n, $dec);
    return $symbol ? setting('currency', 'KSh') . ' ' . $s : $s;
}

function fmt_date(?string $d, string $fmt = 'd M Y'): string
{
    if (!$d || $d === '0000-00-00') {
        return '—';
    }
    $t = strtotime($d);
    return $t ? date($fmt, $t) : '—';
}

function fmt_time(?string $t): string
{
    if (!$t) {
        return '';
    }
    $x = strtotime($t);
    return $x ? date('g:i A', $x) : '';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $out .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $out ?: '?';
}

/* ---------- URLs, redirects, flash ---------- */

function url(string $page = 'dashboard', array $q = []): string
{
    return 'index.php?' . http_build_query(['p' => $page] + $q);
}

function redirect(string $page, array $q = []): never
{
    header('Location: ' . url($page, $q));
    exit;
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = ['t' => $type, 'm' => $msg];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF & input ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    if ($sent === '' || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function post(string $k, string $default = ''): string
{
    $v = $_POST[$k] ?? $default;
    return is_array($v) ? $default : trim((string) $v);
}

function get(string $k, string $default = ''): string
{
    $v = $_GET[$k] ?? $default;
    return is_array($v) ? $default : trim((string) $v);
}

function num($v): float
{
    $v = str_replace([',', ' '], '', (string) $v);
    return is_numeric($v) ? (float) $v : 0.0;
}

function valid_date(?string $d): bool
{
    if (!$d || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $day] = array_map('intval', explode('-', $d));
    return checkdate($m, $day, $y);
}

function valid_time(?string $t): bool
{
    return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $t);
}

function pick(string $v, array $allowed, string $default): string
{
    return in_array($v, $allowed, true) ? $v : $default;
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/* ---------- Settings & logging ---------- */

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (rows('SELECT skey, svalue FROM settings') as $r) {
                $cache[$r['skey']] = (string) $r['svalue'];
            }
        } catch (Throwable $e) {
            // settings table not ready yet
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    if ((int) val('SELECT COUNT(*) FROM settings WHERE skey = ?', [$key]) > 0) {
        q('UPDATE settings SET svalue = ? WHERE skey = ?', [$value, $key]);
    } else {
        q('INSERT INTO settings (skey, svalue) VALUES (?, ?)', [$key, $value]);
    }
}

function log_activity(string $action, string $detail = ''): void
{
    try {
        $u = user();
        q('INSERT INTO activity_log (user_id, action, detail, ip, created_at) VALUES (?,?,?,?,?)', [
            $u['id'] ?? null, $action, mb_substr($detail, 0, 250), client_ip(), date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // logging must never break a request
    }
}

/* ---------- Periods ---------- */

/** @return array{0:?string,1:?string} */
function period_range(string $key, string $from = '', string $to = ''): array
{
    $today = date('Y-m-d');
    switch ($key) {
        case 'today':
            return [$today, $today];
        case 'week':
            return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))];
        case 'month':
            return [date('Y-m-01'), date('Y-m-t')];
        case 'year':
            return [date('Y-01-01'), date('Y-12-31')];
        case 'custom':
            if (valid_date($from) && valid_date($to)) {
                return $from <= $to ? [$from, $to] : [$to, $from];
            }
            if (valid_date($from)) {
                return [$from, $from];
            }
            return [null, null];
        default:
            return [null, null];
    }
}

/** Appends a date-range condition and its params. Use DATE(col) for datetime columns. */
function range_cond(string $col, ?string $from, ?string $to, array &$params): string
{
    if (!$from || !$to) {
        return '';
    }
    $params[] = $from;
    $params[] = $to;
    return " AND $col BETWEEN ? AND ? ";
}

function period_label(?string $from, ?string $to): string
{
    if (!$from || !$to) {
        return 'All time';
    }
    return $from === $to ? fmt_date($from) : fmt_date($from) . ' – ' . fmt_date($to);
}

/* ---------- Sales helpers ---------- */

const SALES_FROM = 'FROM sales s
    JOIN clients c ON c.id = s.client_id
    LEFT JOIN (SELECT sale_id, SUM(amount) AS paid FROM payments GROUP BY sale_id) p ON p.sale_id = s.id';

function pay_status(array $s): string
{
    if (($s['status'] ?? 'active') === 'cancelled') {
        return 'cancelled';
    }
    $paid = (float) ($s['paid'] ?? 0);
    $total = (float) $s['total'];
    if ($paid >= $total - 0.004) {
        return 'paid';
    }
    return $paid > 0 ? 'partial' : 'unpaid';
}

function badge(string $status, ?string $label = null): string
{
    $map = [
        'paid' => 'green', 'partial' => 'gold', 'unpaid' => 'red', 'cancelled' => 'mute',
        'pending' => 'gold', 'confirmed' => 'blue', 'completed' => 'green',
        'queued' => 'mute', 'in_progress' => 'blue', 'review' => 'gold', 'delivered' => 'green',
        'urgent' => 'red', 'normal' => 'mute',
        'available' => 'green', 'in_use' => 'blue', 'maintenance' => 'gold', 'retired' => 'mute',
        'active' => 'green', 'inactive' => 'mute',
    ];
    $label = $label ?? ucwords(str_replace('_', ' ', $status));
    return '<span class="badge b-' . ($map[$status] ?? 'mute') . '">' . e($label) . '</span>';
}

const PAY_METHODS = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank' => 'Bank transfer', 'card' => 'Card', 'other' => 'Other'];

function make_receipt_no(int $id): string
{
    return setting('receipt_prefix', 'DCS-') . date('y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

/* ---------- Pagination ---------- */

function paginate(int $total, int $per = 20): array
{
    $pages = max(1, (int) ceil($total / $per));
    $page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
    return ['page' => $page, 'pages' => $pages, 'per' => $per, 'offset' => ($page - 1) * $per, 'total' => $total];
}

function pager(array $pg, string $pageName, array $params = []): string
{
    if ($pg['pages'] <= 1) {
        return '';
    }
    unset($params['page']);
    $h = '<nav class="pager">';
    if ($pg['page'] > 1) {
        $h .= '<a class="btn sm ghost" href="' . e(url($pageName, $params + ['page' => $pg['page'] - 1])) . '">← Prev</a>';
    }
    $h .= '<span class="muted">Page ' . $pg['page'] . ' of ' . $pg['pages'] . ' · ' . $pg['total'] . ' records</span>';
    if ($pg['page'] < $pg['pages']) {
        $h .= '<a class="btn sm ghost" href="' . e(url($pageName, $params + ['page' => $pg['page'] + 1])) . '">Next →</a>';
    }
    return $h . '</nav>';
}

/* ---------- Trend data (for charts) ---------- */

/**
 * Billed + collected per day (or per month for long ranges).
 * @return array{labels:string[],billed:float[],collected:float[]}
 */
function trend_series(?string $from, ?string $to): array
{
    $today = date('Y-m-d');
    if (!$from || !$to) {
        $first = val("SELECT MIN(sale_date) FROM sales WHERE status = 'active'");
        $from = $first ? (string) $first : date('Y-m-01');
        $to = $today;
        $monthsBack = date('Y-m-01', strtotime('-11 months'));
        if ($from < $monthsBack) {
            $from = $monthsBack;
        }
    }
    $days = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
    $monthly = $days > 62;

    $billed = [];
    foreach (rows("SELECT sale_date AS d, SUM(total) AS t FROM sales WHERE status = 'active' AND sale_date BETWEEN ? AND ? GROUP BY sale_date", [$from, $to]) as $r) {
        $k = $monthly ? substr($r['d'], 0, 7) : $r['d'];
        $billed[$k] = ($billed[$k] ?? 0) + (float) $r['t'];
    }
    $coll = [];
    foreach (rows("SELECT DATE(p.paid_at) AS d, SUM(p.amount) AS t FROM payments p JOIN sales s ON s.id = p.sale_id WHERE s.status = 'active' AND DATE(p.paid_at) BETWEEN ? AND ? GROUP BY DATE(p.paid_at)", [$from, $to]) as $r) {
        $k = $monthly ? substr((string) $r['d'], 0, 7) : (string) $r['d'];
        $coll[$k] = ($coll[$k] ?? 0) + (float) $r['t'];
    }

    $labels = $b = $c = [];
    if ($monthly) {
        $cur = strtotime(date('Y-m-01', strtotime($from)));
        $end = strtotime(date('Y-m-01', strtotime($to)));
        while ($cur <= $end) {
            $k = date('Y-m', $cur);
            $labels[] = date('M y', $cur);
            $b[] = $billed[$k] ?? 0;
            $c[] = $coll[$k] ?? 0;
            $cur = strtotime('+1 month', $cur);
        }
    } else {
        $cur = strtotime($from);
        $end = strtotime($to);
        while ($cur <= $end) {
            $k = date('Y-m-d', $cur);
            $labels[] = $days <= 10 ? date('D j', $cur) : date('j M', $cur);
            $b[] = $billed[$k] ?? 0;
            $c[] = $coll[$k] ?? 0;
            $cur = strtotime('+1 day', $cur);
        }
    }
    return ['labels' => $labels, 'billed' => $b, 'collected' => $c];
}

/* ---------- CSV ---------- */

function csv_download(string $filename, array $header, array $rowsData): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rowsData as $r) {
        // neutralise spreadsheet formula injection
        $r = array_map(static fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@") !== false && !is_numeric($v) ? "'" . $v : $v, $r);
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}

function chart_json(array $data): string
{
    return e(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK));
}

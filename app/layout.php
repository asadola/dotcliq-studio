<?php
declare(strict_types=1);

function icon(string $name, int $size = 18): string
{
    static $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
        'sales'     => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'camera'    => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
        'layers'    => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
        'card'      => '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>',
        'box'       => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
        'chart'     => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
        'settings'  => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'shield'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'activity'  => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'plus'      => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        'menu'      => '<line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/>',
        'moon'      => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
        'search'    => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
        'printer'   => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>',
        'x'         => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
        'check'     => '<polyline points="20 6 9 17 4 12"/>',
        'trash'     => '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>',
        'edit'      => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'eye'       => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
        'tag'       => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
        'phone'     => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'target'    => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'arrow'     => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
    ];
    return '<svg class="ic" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

function nav_items(): array
{
    return [
        ['dashboard', 'dashboard', 'Dashboard', 'dashboard'],
        ['sales', 'sales', 'Sales', 'sales'],
        ['bookings', 'bookings', 'Bookings', 'calendar'],
        ['jobs', 'jobs', 'Editing & Delivery', 'layers'],
        ['clients', 'clients', 'Clients', 'users'],
        ['expenses', 'expenses', 'Expenses', 'card'],
        ['reports', 'reports', 'Reports', 'chart'],
        ['packages', 'packages', 'Packages', 'tag'],
        ['equipment', 'equipment', 'Equipment', 'camera'],
        ['users', 'users', 'Team & Users', 'shield'],
        ['settings', 'settings', 'Settings', 'settings'],
        ['activity', 'activity', 'Activity Log', 'activity'],
    ];
}

function brand_html(): string
{
    $name = trim(setting('studio_name', 'DotCliq Studio')) ?: 'DotCliq Studio';
    $parts = preg_split('/\s+/', $name, 2) ?: [$name];
    $first = mb_strtoupper($parts[0]);
    $rest = isset($parts[1]) ? mb_strtoupper($parts[1]) : '';
    return '<span class="bn">' . e($first) . '</span>' . ($rest !== '' ? ' <span class="bn gold">' . e($rest) . '</span>' : '');
}

function logo_mark(): string
{
    return '<span class="logo" aria-hidden="true"><svg viewBox="0 0 40 40" width="26" height="26"><path d="M30 12.5A12.5 12.5 0 1 0 30 27.5" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round"/><circle cx="30" cy="20" r="3.4" fill="#f6b900"/></svg></span>';
}

function page_start(string $title, string $active = '', array $opts = []): void
{
    $u = user();
    $studio = setting('studio_name', 'DotCliq Studio');
    $flashes = take_flash();
    ?><!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#14030b">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title) ?> · <?= e($studio) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='10' fill='%23a31f37'/%3E%3Cpath d='M29 13a11 11 0 1 0 0 14' fill='none' stroke='white' stroke-width='5' stroke-linecap='round'/%3E%3C/svg%3E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
<script>try{var t=localStorage.getItem('dc-theme');if(t)document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="app">
  <aside class="side" id="side">
    <a class="brand" href="<?= e(url('dashboard')) ?>"><?= logo_mark() ?><span class="bt"><?= brand_html() ?><small><?= e(setting('brand_tag', 'Dream it, We Create it')) ?></small></span></a>
    <nav class="nav">
      <?php foreach (nav_items() as [$perm, $page, $label, $ic]):
          if (!can($perm)) continue; ?>
        <a href="<?= e(url($page)) ?>" class="<?= $active === $page ? 'on' : '' ?>"><?= icon($ic) ?><span><?= e($label) ?></span></a>
      <?php endforeach; ?>
    </nav>
    <?php if ($u): ?>
    <div class="side-foot">
      <a class="me" href="<?= e(url('profile')) ?>" title="My profile">
        <span class="av"><?= e(initials($u['name'])) ?></span>
        <span class="mt"><b><?= e($u['name']) ?></b><small><?= e(role_label($u['role'])) ?></small></span>
      </a>
      <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button class="btn ghost sm block" type="submit"><?= icon('logout', 16) ?> Log out</button></form>
    </div>
    <?php endif; ?>
  </aside>
  <div class="scrim" id="scrim"></div>
  <div class="main">
    <header class="top">
      <button class="icon-btn menu-btn" id="menuBtn" aria-label="Open menu"><?= icon('menu', 20) ?></button>
      <div class="top-title"><?= e($title) ?></div>
      <div class="top-actions">
        <?php if (can('sales')): ?><a class="btn sm" href="<?= e(url('sale_form')) ?>"><?= icon('plus', 16) ?><span class="hide-sm"> New Sale</span></a><?php endif; ?>
        <button class="icon-btn" id="themeBtn" aria-label="Toggle light/dark theme" title="Toggle theme"><?= icon('moon', 18) ?></button>
      </div>
    </header>
    <main class="content">
      <?php foreach ($flashes as $f): ?>
        <div class="alert a-<?= e($f['t']) ?>" role="status"><?= e($f['m']) ?><button class="alert-x" aria-label="Dismiss">×</button></div>
      <?php endforeach; ?>
<?php
}

function page_end(): void
{
    ?>
    </main>
    <footer class="foot"><?= e(setting('studio_name', 'DotCliq Studio')) ?> · Management System v<?= APP_VERSION ?></footer>
  </div>
</div>
<script src="assets/js/app.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
<?php
}

/** Standard page heading with optional right-aligned actions (raw HTML). */
function page_head(string $title, string $sub = '', string $actions = ''): void
{
    echo '<div class="ph"><div><h1>' . e($title) . '</h1>' . ($sub !== '' ? '<p class="muted">' . e($sub) . '</p>' : '') . '</div>'
        . ($actions !== '' ? '<div class="ph-actions">' . $actions . '</div>' : '') . '</div>';
}

/** Date-range filter chips + custom range form. Returns [from,to,key]. */
function period_current(): array
{
    $key = pick(get('period', 'month'), ['all', 'today', 'week', 'month', 'year', 'custom'], 'month');
    [$f, $t] = period_range($key, get('from'), get('to'));
    if ($key === 'custom' && !$f) {
        $key = 'all';
    }
    return [$f, $t, $key];
}

function period_filter(string $pageName, array $extra = [], bool $withYear = false): array
{
    [$f, $t, $key] = period_current();
    $chips = ['all' => 'All time', 'today' => 'Today', 'week' => 'This week', 'month' => 'This month'];
    if ($withYear) {
        $chips['year'] = 'This year';
    }
    echo '<div class="card filter"><div class="filter-l"><span class="ficon">' . icon('calendar', 18) . '</span><div><b>Timeframe</b><small class="muted">' . e(period_label($f, $t)) . '</small></div></div><div class="chips">';
    foreach ($chips as $k => $label) {
        echo '<a class="chip ' . ($key === $k ? 'on' : '') . '" href="' . e(url($pageName, $extra + ['period' => $k])) . '">' . e($label) . '</a>';
    }
    echo '<form class="range" method="get" action="index.php"><input type="hidden" name="p" value="' . e($pageName) . '"><input type="hidden" name="period" value="custom">';
    foreach ($extra as $k => $v) {
        echo '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    }
    echo '<input type="date" name="from" value="' . e($f ?? '') . '" aria-label="From date"><span class="muted">to</span><input type="date" name="to" value="' . e($t ?? '') . '" aria-label="To date"><button class="btn sm ghost" type="submit">Apply</button></form>';
    echo '</div></div>';
    return [$f, $t, $key];
}

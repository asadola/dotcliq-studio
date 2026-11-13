<?php
declare(strict_types=1);

require_can('reports');

$tabs = [
    'tracker' => 'Sales Tracker',
    'revenue' => 'Revenue',
    'collections' => 'Collections',
    'expenses' => 'Expenses',
    'pnl' => 'Profit & Loss',
    'outstanding' => 'Outstanding',
];
$tab = pick(get('r', 'tracker'), array_keys($tabs), 'tracker');
$export = get('export') === '1';
$today = date('Y-m-d');

function pct_change(float $now, float $prev): string
{
    if ($prev <= 0) {
        return $now > 0 ? 'new' : '—';
    }
    $c = ($now - $prev) / $prev * 100;
    return ($c >= 0 ? '▲ ' : '▼ ') . abs(round($c)) . '%';
}

function tab_nav(array $tabs, string $tab): void
{
    echo '<div class="tabs" role="tablist">';
    foreach ($tabs as $k => $l) {
        echo '<a class="' . ($k === $tab ? 'on' : '') . '" href="' . e(url('reports', ['r' => $k])) . '">' . e($l) . '</a>';
    }
    echo '</div>';
}

function label_rows(string $cond, array $p): array
{
    return rows("SELECT COALESCE(pk.name, si.description) AS label, SUM(si.qty) AS qty, SUM(si.line_total) AS v
        FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN packages pk ON pk.id = si.package_id
        WHERE s.status = 'active' $cond GROUP BY COALESCE(pk.name, si.description) ORDER BY v DESC", $p);
}

/* =====================================================================
 * SALES TRACKER (monthly target view)
 * ===================================================================== */
if ($tab === 'tracker') {
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', get('m')) ? get('m') : date('Y-m');
    $first = $month . '-01';
    $last = date('Y-m-t', strtotime($first));
    $dim = (int) date('t', strtotime($first));
    $prevFirst = date('Y-m-01', strtotime($first . ' -1 month'));
    $prevLast = date('Y-m-t', strtotime($prevFirst));
    $target = (float) setting('monthly_target', '150000');

    $billedOf = fn(string $a, string $b): float => (float) val("SELECT COALESCE(SUM(total),0) FROM sales WHERE status = 'active' AND sale_date BETWEEN ? AND ?", [$a, $b]);
    $billed = $billedOf($first, $last);
    $prevBilled = $billedOf($prevFirst, $prevLast);
    $salesN = (int) val("SELECT COUNT(*) FROM sales WHERE status = 'active' AND sale_date BETWEEN ? AND ?", [$first, $last]);
    $collected = (float) val("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales s ON s.id = p.sale_id WHERE s.status = 'active' AND DATE(p.paid_at) BETWEEN ? AND ?", [$first, $last]);

    $daily = array_fill(1, $dim, 0.0);
    foreach (rows("SELECT sale_date AS d, SUM(total) AS t FROM sales WHERE status = 'active' AND sale_date BETWEEN ? AND ? GROUP BY sale_date", [$first, $last]) as $r) {
        $daily[(int) substr($r['d'], 8, 2)] = (float) $r['t'];
    }
    $isCurrent = $month === date('Y-m');
    $isFuture = $month > date('Y-m');
    $elapsed = $isFuture ? 0 : ($isCurrent ? (int) date('j') : $dim);
    $projection = $elapsed > 0 ? $billed / $elapsed * $dim : 0.0;
    $remaining = max(0.0, $target - $billed);
    $daysLeft = max(0, $dim - $elapsed);
    $perDay = $daysLeft > 0 ? $remaining / $daysLeft : $remaining;
    $pct = $target > 0 ? $billed / $target * 100 : 0;
    $bestDay = $daily ? array_keys($daily, max($daily))[0] : 0;
    $bestVal = $daily ? max($daily) : 0;

    $pp = [$first, $last];
    $staffRows = rows("SELECT COALESCE(u.name, 'Unassigned') AS name, COUNT(*) AS n, SUM(s.total) AS billed, COALESCE(SUM(p.paid),0) AS paid
        " . SALES_FROM . " LEFT JOIN users u ON u.id = s.sold_by
        WHERE s.status = 'active' AND s.sale_date BETWEEN ? AND ? GROUP BY s.sold_by, u.name ORDER BY billed DESC", $pp);
    $pkgRows = label_rows(' AND s.sale_date BETWEEN ? AND ? ', $pp);
    $newClients = (int) val("SELECT COUNT(*) FROM (SELECT client_id, MIN(sale_date) AS d FROM sales WHERE status = 'active' GROUP BY client_id) x WHERE d BETWEEN ? AND ?", $pp);
    $activeClients = (int) val("SELECT COUNT(DISTINCT client_id) FROM sales WHERE status = 'active' AND sale_date BETWEEN ? AND ?", $pp);

    if ($export) {
        $out = [];
        foreach ($daily as $d => $t) {
            $out[] = [$month . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT), $t];
        }
        log_activity('export', 'Tracker ' . $month);
        csv_download('sales-tracker-' . $month . '.csv', ['Date', 'Billed'], $out);
    }

    page_start('Reports', 'reports');
    page_head('Reports', 'Sales tracking, collections, expenses and profit', '<a class="btn ghost" href="' . e(url('reports', ['r' => 'tracker', 'm' => $month, 'export' => 1])) . '">' . icon('download', 16) . ' Export CSV</a>');
    tab_nav($tabs, $tab);
    ?>
    <div class="card filter">
      <div class="row">
        <a class="btn sm ghost" href="<?= e(url('reports', ['r' => 'tracker', 'm' => date('Y-m', strtotime($first . ' -1 month'))])) ?>">←</a>
        <b style="min-width:140px;text-align:center"><?= e(date('F Y', strtotime($first))) ?></b>
        <a class="btn sm ghost" href="<?= e(url('reports', ['r' => 'tracker', 'm' => date('Y-m', strtotime($first . ' +1 month'))])) ?>">→</a>
        <a class="btn sm ghost" href="<?= e(url('reports', ['r' => 'tracker'])) ?>">This month</a>
      </div>
      <span class="muted small">Monthly target <b class="gold-t"><?= money($target) ?></b><?php if (is_admin()): ?> · <a href="<?= e(url('settings')) ?>">change</a><?php endif; ?></span>
    </div>

    <div class="card">
      <div class="card-h"><h2><?= icon('target') ?> Target progress</h2><b class="<?= $pct >= 100 ? 'green-t' : 'gold-t' ?>" style="font-size:20px"><?= round($pct) ?>%</b></div>
      <div class="bar" style="height:14px"><i style="width:<?= round(min(100, $pct)) ?>%"></i></div>
      <div class="grid g4" style="margin-top:16px">
        <div><div class="muted small">Billed so far</div><b style="font-size:20px"><?= money($billed) ?></b><div class="small <?= $billed >= $prevBilled ? 'green-t' : 'red-t' ?>"><?= pct_change($billed, $prevBilled) ?> vs <?= e(date('M', strtotime($prevFirst))) ?></div></div>
        <div><div class="muted small">Still to hit target</div><b style="font-size:20px"><?= money($remaining) ?></b><div class="small muted"><?= $daysLeft > 0 ? money($perDay) . ' / day for ' . $daysLeft . ' days' : ($remaining > 0 ? 'Month closed' : 'Target reached 🎯') ?></div></div>
        <div><div class="muted small">Projected month-end</div><b style="font-size:20px"><?= $elapsed > 0 ? money($projection) : '—' ?></b><div class="small muted"><?= $isCurrent ? 'At the current daily pace' : '' ?></div></div>
        <div><div class="muted small">Collected (cash in)</div><b style="font-size:20px" class="green-t"><?= money($collected) ?></b><div class="small muted"><?= $salesN ?> sales · avg <?= money($salesN ? $billed / $salesN : 0) ?></div></div>
      </div>
    </div>

    <div class="grid g-main">
      <div class="card">
        <div class="card-h"><h2><?= icon('chart') ?> Daily sales</h2><span class="muted small">Dashed line = daily average needed for target</span></div>
        <div class="chart" data-type="bar" data-chart='<?= chart_json(['labels' => array_map('strval', array_keys($daily)), 'values' => array_values($daily), 'target' => $target > 0 ? $target / $dim : 0]) ?>'></div>
        <?php if ($bestVal > 0): ?><p class="muted small" style="margin-top:8px">Best day: <b><?= (int) $bestDay ?> <?= e(date('M', strtotime($first))) ?></b> with <?= money($bestVal) ?></p><?php endif; ?>
      </div>
      <div class="card">
        <div class="card-h"><h2><?= icon('users') ?> Clients</h2></div>
        <div class="mini"><span>Active this month</span><b><?= $activeClients ?></b></div>
        <div class="mini"><span>New clients</span><b class="green-t"><?= $newClients ?></b></div>
        <div class="mini"><span>Returning clients</span><b><?= max(0, $activeClients - $newClients) ?></b></div>
      </div>
    </div>

    <div class="grid g2">
      <div class="card">
        <div class="card-h"><h2>Sales team leaderboard</h2></div>
        <?php if (!$staffRows): ?><p class="muted">No sales this month.</p><?php else: ?>
        <div class="tbl-wrap"><table class="tbl stack"><thead><tr><th>Staff</th><th class="r">Sales</th><th class="r">Billed</th><th class="r">Collected</th></tr></thead><tbody>
          <?php foreach ($staffRows as $i => $r): ?><tr><td data-label="Staff"><b><?= ($i + 1) . '. ' . e($r['name']) ?></b></td><td data-label="Sales" class="r"><?= (int) $r['n'] ?></td><td data-label="Billed" class="r"><?= money($r['billed']) ?></td><td data-label="Collected" class="r green-t"><?= money($r['paid']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </div>
      <div class="card">
        <div class="card-h"><h2>Best-selling services</h2></div>
        <?php if (!$pkgRows): ?><p class="muted">No sales this month.</p><?php else: ?>
        <div class="tbl-wrap"><table class="tbl stack"><thead><tr><th>Service</th><th class="r">Qty</th><th class="r">Revenue</th></tr></thead><tbody>
          <?php foreach (array_slice($pkgRows, 0, 8) as $r): ?><tr><td data-label="Service"><?= e($r['label']) ?></td><td data-label="Qty" class="r"><?= (int) $r['qty'] ?></td><td data-label="Revenue" class="r"><b><?= money($r['v']) ?></b></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* =====================================================================
 * REVENUE
 * ===================================================================== */
if ($tab === 'revenue') {
    [$from, $to, $key] = period_current();
    $p = [];
    $cond = range_cond('s.sale_date', $from, $to, $p);
    $sum = row('SELECT COUNT(*) AS n, COALESCE(SUM(s.subtotal),0) AS sub, COALESCE(SUM(s.discount),0) AS disc, COALESCE(SUM(s.total),0) AS billed, COALESCE(SUM(COALESCE(p.paid,0)),0) AS paid ' . SALES_FROM . " WHERE s.status = 'active' $cond", $p);
    $pkg = label_rows($cond, $p);
    $staffRows = rows("SELECT COALESCE(u.name, 'Unassigned') AS name, COUNT(*) AS n, SUM(s.total) AS billed " . SALES_FROM . " LEFT JOIN users u ON u.id = s.sold_by WHERE s.status = 'active' $cond GROUP BY s.sold_by, u.name ORDER BY billed DESC", $p);
    $clientsTop = rows('SELECT c.id, c.name, COUNT(*) AS n, SUM(s.total) AS billed ' . SALES_FROM . " WHERE s.status = 'active' $cond GROUP BY c.id, c.name ORDER BY billed DESC LIMIT 10", $p);
    $trend = trend_series($from, $to);

    if ($export) {
        csv_download('revenue-by-service-' . date('Ymd') . '.csv', ['Service', 'Quantity', 'Revenue (before discount)'], array_map(fn($r) => [$r['label'], $r['qty'], $r['v']], $pkg));
    }
    page_start('Reports', 'reports');
    page_head('Reports', 'Revenue analysis · ' . period_label($from, $to), '<a class="btn ghost" href="' . e(url('reports', ['r' => 'revenue', 'period' => $key, 'from' => $from, 'to' => $to, 'export' => 1])) . '">' . icon('download', 16) . ' Export CSV</a>');
    tab_nav($tabs, $tab);
    period_filter('reports', ['r' => 'revenue'], true);
    ?>
    <div class="grid g4">
      <div class="card kpi"><span class="lbl">Billed (net)</span><span class="val"><?= money($sum['billed']) ?></span><div class="sub"><span><?= (int) $sum['n'] ?> sales</span></div></div>
      <div class="card kpi"><span class="lbl">Discounts given</span><span class="val"><?= money($sum['disc']) ?></span></div>
      <div class="card kpi green"><span class="lbl">Collected on these sales</span><span class="val"><?= money($sum['paid']) ?></span></div>
      <div class="card kpi hl"><span class="lbl">Still owing</span><span class="val"><?= money(max(0, $sum['billed'] - $sum['paid'])) ?></span></div>
    </div>
    <div class="card"><div class="card-h"><h2>Revenue trend</h2></div><div class="chart" data-type="line" data-chart='<?= chart_json(['labels' => $trend['labels'], 'series' => [['name' => 'Billed', 'color' => '#f6b900', 'values' => $trend['billed']], ['name' => 'Collected', 'color' => '#33d6a0', 'values' => $trend['collected']]]]) ?>'></div></div>
    <div class="grid g2">
      <div class="card"><div class="card-h"><h2>By service / package</h2></div>
        <?php if (!$pkg): ?><p class="muted">No data.</p><?php else: ?><div class="tbl-wrap"><table class="tbl stack"><thead><tr><th>Service</th><th class="r">Qty</th><th class="r">Revenue</th></tr></thead><tbody>
          <?php foreach ($pkg as $r): ?><tr><td data-label="Service"><?= e($r['label']) ?></td><td data-label="Qty" class="r"><?= (int) $r['qty'] ?></td><td data-label="Revenue" class="r"><b><?= money($r['v']) ?></b></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
      <div>
        <div class="card"><div class="card-h"><h2>By staff member</h2></div>
          <?php if (!$staffRows): ?><p class="muted">No data.</p><?php else: ?><div class="tbl-wrap"><table class="tbl stack"><thead><tr><th>Staff</th><th class="r">Sales</th><th class="r">Billed</th></tr></thead><tbody>
            <?php foreach ($staffRows as $r): ?><tr><td data-label="Staff"><?= e($r['name']) ?></td><td data-label="Sales" class="r"><?= (int) $r['n'] ?></td><td data-label="Billed" class="r"><b><?= money($r['billed']) ?></b></td></tr><?php endforeach; ?>
          </tbody></table></div><?php endif; ?></div>
        <div class="card"><div class="card-h"><h2>Top clients</h2></div>
          <?php if (!$clientsTop): ?><p class="muted">No data.</p><?php else: ?><div class="mini-list"><?php foreach ($clientsTop as $r): ?>
            <div class="mini"><div class="l"><a href="<?= e(url('client_view', ['id' => $r['id']])) ?>"><b><?= e($r['name']) ?></b></a><span class="sub-line"><?= (int) $r['n'] ?> sale<?= (int) $r['n'] === 1 ? '' : 's' ?></span></div><b><?= money($r['billed']) ?></b></div>
          <?php endforeach; ?></div><?php endif; ?></div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* =====================================================================
 * COLLECTIONS (cash in)
 * ===================================================================== */
if ($tab === 'collections') {
    [$from, $to, $key] = period_current();
    $p = [];
    $cond = range_cond('DATE(p.paid_at)', $from, $to, $p);
    $base = "FROM payments p JOIN sales s ON s.id = p.sale_id JOIN clients c ON c.id = s.client_id LEFT JOIN users u ON u.id = p.received_by WHERE s.status = 'active' $cond";
    $byMethod = rows("SELECT p.method, COUNT(*) AS n, SUM(p.amount) AS t $base GROUP BY p.method ORDER BY t DESC", $p);
    $byUser = rows("SELECT COALESCE(u.name,'—') AS name, COUNT(*) AS n, SUM(p.amount) AS t $base GROUP BY p.received_by, u.name ORDER BY t DESC", $p);
    $list = rows("SELECT p.*, s.receipt_no, s.id AS sid, c.name AS client, u.name AS who $base ORDER BY p.paid_at DESC, p.id DESC LIMIT 300", $p);
    $total = array_sum(array_map(fn($r) => (float) $r['t'], $byMethod));

    if ($export) {
        csv_download('collections-' . date('Ymd') . '.csv', ['Date', 'Receipt', 'Client', 'Method', 'Reference', 'Amount', 'Received by'],
            array_map(fn($r) => [substr((string) $r['paid_at'], 0, 10), $r['receipt_no'], $r['client'], PAY_METHODS[$r['method']] ?? $r['method'], $r['reference'], $r['amount'], $r['who']], $list));
    }
    page_start('Reports', 'reports');
    page_head('Reports', 'Money received · ' . period_label($from, $to), '<a class="btn ghost" href="' . e(url('reports', ['r' => 'collections', 'period' => $key, 'from' => $from, 'to' => $to, 'export' => 1])) . '">' . icon('download', 16) . ' Export CSV</a>');
    tab_nav($tabs, $tab);
    period_filter('reports', ['r' => 'collections'], true);
    ?>
    <div class="grid g-main">
      <div class="card">
        <div class="card-h"><h2>Payments received</h2><b class="green-t"><?= money($total) ?></b></div>
        <?php if (!$list): ?><p class="muted">No payments in this timeframe.</p><?php else: ?>
        <div class="tbl-wrap"><table class="tbl stack"><thead><tr><th>Date</th><th>Client</th><th>Receipt</th><th>Method</th><th>Reference</th><th class="r">Amount</th></tr></thead><tbody>
          <?php foreach ($list as $r): ?><tr>
            <td data-label="Date" class="nowrap"><?= e(fmt_date(substr((string) $r['paid_at'], 0, 10), 'd M')) ?></td>
            <td data-label="Client"><?= e($r['client']) ?><span class="sub-line"><?= e($r['who']) ?></span></td>
            <td data-label="Receipt"><a href="<?= e(url('sale_view', ['id' => $r['sid']])) ?>"><?= e($r['receipt_no']) ?></a></td>
            <td data-label="Method"><?= e(PAY_METHODS[$r['method']] ?? $r['method']) ?></td>
            <td data-label="Reference"><?= e($r['reference'] ?: '—') ?></td>
            <td data-label="Amount" class="r green-t"><b><?= money($r['amount']) ?></b></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
      </div>
      <div>
        <div class="card"><div class="card-h"><h2>By payment method</h2></div>
          <div class="chart" data-type="donut" data-chart='<?= chart_json(['items' => array_map(fn($r) => ['label' => PAY_METHODS[$r['method']] ?? $r['method'], 'value' => (float) $r['t']], $byMethod), 'center' => 'received']) ?>'></div></div>
        <div class="card"><div class="card-h"><h2>By receiver</h2></div>
          <div class="mini-list"><?php foreach ($byUser as $r): ?><div class="mini"><div class="l"><b><?= e($r['name']) ?></b><span class="sub-line"><?= (int) $r['n'] ?> payment<?= (int) $r['n'] === 1 ? '' : 's' ?></span></div><b><?= money($r['t']) ?></b></div><?php endforeach; ?></div></div>
      </div>
    </div>
    <?php
    page_end();
    return;
}

/* =====================================================================
 * EXPENSES
 * ===================================================================== */
if ($tab === 'expenses') {
    [$from, $to, $key] = period_current();
    $p = [];
    $cond = range_cond('e.expense_date', $from, $to, $p);
    $byCat = rows("SELECT e.category, COUNT(*) AS n, SUM(e.amount) AS t FROM expenses e WHERE 1=1 $cond GROUP BY e.category ORDER BY t DESC", $p);
    $total = array_sum(array_map(fn($r) => (float) $r['t'], $byCat));
    $collP = [];
    $collC = range_cond('DATE(p.paid_at)', $from, $to, $collP);
    $collected = (float) val("SELECT COALESCE(SUM(p.amount),0) FROM payments p JOIN sales s ON s.id = p.sale_id WHERE s.status = 'active' $collC", $collP);

    if ($export) {
        csv_download('expenses-by-category-' . date('Ymd') . '.csv', ['Category', 'Entries', 'Total'], array_map(fn($r) => [$r['category'], $r['n'], $r['t']], $byCat));
    }
    page_start('Reports', 'reports');
    page_head('Reports', 'Where the money went · ' . period_label($from, $to), '<a class="btn ghost" href="' . e(url('reports', ['r' => 'expenses', 'period' => $key, 'from' => $from, 'to' => $to, 'export' => 1])) . '">' . icon('download', 16) . ' Export CSV</a>');
    tab_nav($tabs, $tab);
    period_filter('reports', ['r' => 'expenses'], true);
    ?>
    <div class="grid g3">
      <div class="card kpi red"><span class="lbl">Total expenses</span><span class="val"><?= money($total) ?></span></div>
      <div class="card kpi green"><span class="lbl">Collected in period</span><span class="val"><?= money($collected) ?></span></div>
      <div class="card kpi hl"><span class="lbl">Expense ratio</span><span class="val"><?= $collected > 0 ? round($total / $collected * 100) . '%' : '—' ?></span><div class="sub"><span>of money collected</span></div></div>
    </div>
    <div class="grid g-main">
      <div class="card"><div class="card-h"><h2>By category</h2><a class="btn sm ghost" href="<?= e(url('expenses', ['period' => $key, 'from' => $from, 'to' => $to])) ?>">View entries</a></div>
        <?php if (!$byCat): ?><p class="muted">No expenses in this timeframe.</p><?php else: ?>
        <div class="tbl-wrap"><table class="tbl stack"><thead><tr><th>Category</th><th class="r">Entries</th><th class="r">Total</th><th class="r">Share</th></tr></thead><tbody>
          <?php foreach ($byCat as $r): ?><tr><td data-label="Category"><?= e($r['category']) ?></td><td data-label="Entries" class="r"><?= (int) $r['n'] ?></td><td data-label="Total" class="r"><b><?= money($r['t']) ?></b></td><td data-label="Share" class="r"><?= $total > 0 ? round($r['t'] / $total * 100) : 0 ?>%</td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?></div>
      <div class="card"><div class="card-h"><h2>Breakdown</h2></div>
        <div class="chart" data-type="donut" data-chart='<?= chart_json(['items' => array_map(fn($r) => ['label' => $r['category'], 'value' => (float) $r['t']], $byCat), 'center' => 'spent']) ?>'></div></div>
    </div>
    <?php
    page_end();
    return;
}

/* =====================================================================
 * PROFIT & LOSS — last 12 months
 * ===================================================================== */
if ($tab === 'pnl') {
    $startMonth = date('Y-m-01', strtotime('-11 months'));
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $months[date('Y-m', strtotime($startMonth . " +$i months"))] = ['billed' => 0.0, 'collected' => 0.0, 'expenses' => 0.0];
    }
    foreach (rows("SELECT SUBSTR(CAST(sale_date AS CHAR), 1, 7) AS m, SUM(total) AS t FROM sales WHERE status = 'active' AND sale_date >= ? GROUP BY 1", [$startMonth]) as $r) {
        if (isset($months[$r['m']])) $months[$r['m']]['billed'] = (float) $r['t'];
    }
    foreach (rows("SELECT SUBSTR(CAST(p.paid_at AS CHAR), 1, 7) AS m, SUM(p.amount) AS t FROM payments p JOIN sales s ON s.id = p.sale_id WHERE s.status = 'active' AND DATE(p.paid_at) >= ? GROUP BY 1", [$startMonth]) as $r) {
        if (isset($months[$r['m']])) $months[$r['m']]['collected'] = (float) $r['t'];
    }
    foreach (rows("SELECT SUBSTR(CAST(expense_date AS CHAR), 1, 7) AS m, SUM(amount) AS t FROM expenses WHERE expense_date >= ? GROUP BY 1", [$startMonth]) as $r) {
        if (isset($months[$r['m']])) $months[$r['m']]['expenses'] = (float) $r['t'];
    }
    $tot = ['billed' => 0.0, 'collected' => 0.0, 'expenses' => 0.0];
    foreach ($months as $m) {
        foreach ($tot as $k => $_) $tot[$k] += $m[$k];
    }
    if ($export) {
        csv_download('profit-and-loss-' . date('Ymd') . '.csv', ['Month', 'Billed', 'Collected', 'Expenses', 'Net profit (collected - expenses)'],
            array_map(fn($k, $m) => [$k, $m['billed'], $m['collected'], $m['expenses'], $m['collected'] - $m['expenses']], array_keys($months), array_values($months)));
    }
    $labels = array_map(fn($k) => date('M y', strtotime($k . '-01')), array_keys($months));
    page_start('Reports', 'reports');
    page_head('Reports', 'Last 12 months · net profit = money collected − expenses', '<a class="btn ghost" href="' . e(url('reports', ['r' => 'pnl', 'export' => 1])) . '">' . icon('download', 16) . ' Export CSV</a>');
    tab_nav($tabs, $tab);
    ?>
    <div class="grid g4">
      <div class="card kpi"><span class="lbl">Billed (12 mo)</span><span class="val"><?= money($tot['billed']) ?></span></div>
      <div class="card kpi green"><span class="lbl">Collected</span><span class="val"><?= money($tot['collected']) ?></span></div>
      <div class="card kpi red"><span class="lbl">Expenses</span><span class="val"><?= money($tot['expenses']) ?></span></div>
      <div class="card kpi hl"><span class="lbl">Net profit</span><span class="val"><?= $tot['collected'] - $tot['expenses'] < 0 ? '− ' : '' ?><?= money(abs($tot['collected'] - $tot['expenses'])) ?></span></div>
    </div>
    <div class="card"><div class="card-h"><h2>Monthly performance</h2></div>
      <div class="chart" data-type="line" data-chart='<?= chart_json(['labels' => $labels, 'series' => [['name' => 'Collected', 'color' => '#33d6a0', 'values' => array_column($months, 'collected')], ['name' => 'Expenses', 'color' => '#ff7b93', 'values' => array_column($months, 'expenses')], ['name' => 'Billed', 'color' => '#f6b900', 'values' => array_column($months, 'billed')]]]) ?>'></div></div>
    <div class="card"><div class="tbl-wrap"><table class="tbl stack">
      <thead><tr><th>Month</th><th class="r">Billed</th><th class="r">Collected</th><th class="r">Expenses</th><th class="r">Net profit</th></tr></thead>
      <tbody><?php foreach (array_reverse($months, true) as $k => $m): $n = $m['collected'] - $m['expenses']; ?>
        <tr><td data-label="Month"><?= e(date('F Y', strtotime($k . '-01'))) ?></td><td data-label="Billed" class="r"><?= money($m['billed']) ?></td><td data-label="Collected" class="r green-t"><?= money($m['collected']) ?></td><td data-label="Expenses" class="r red-t"><?= money($m['expenses']) ?></td><td data-label="Net" class="r <?= $n < 0 ? 'red-t' : 'gold-t' ?>"><b><?= $n < 0 ? '− ' : '' ?><?= money(abs($n)) ?></b></td></tr>
      <?php endforeach; ?></tbody>
      <tfoot><tr><td>Total</td><td class="r"><?= money($tot['billed']) ?></td><td class="r"><?= money($tot['collected']) ?></td><td class="r"><?= money($tot['expenses']) ?></td><td class="r"><?= money($tot['collected'] - $tot['expenses']) ?></td></tr></tfoot>
    </table></div></div>
    <?php
    page_end();
    return;
}

/* =====================================================================
 * OUTSTANDING balances (aged)
 * ===================================================================== */
$owing = rows('SELECT s.id, s.receipt_no, s.sale_date, s.total, COALESCE(p.paid,0) AS paid, c.name, c.phone ' . SALES_FROM
    . " WHERE s.status = 'active' AND s.total - COALESCE(p.paid,0) > 0.004 ORDER BY s.sale_date ASC LIMIT 500");
$buckets = ['0-7 days' => 0.0, '8-30 days' => 0.0, '31-60 days' => 0.0, '60+ days' => 0.0];
$sumOwing = 0.0;
foreach ($owing as &$o) {
    $o['bal'] = (float) $o['total'] - (float) $o['paid'];
    $o['age'] = max(0, (int) floor((strtotime($today) - strtotime($o['sale_date'])) / 86400));
    $sumOwing += $o['bal'];
    $bk = $o['age'] <= 7 ? '0-7 days' : ($o['age'] <= 30 ? '8-30 days' : ($o['age'] <= 60 ? '31-60 days' : '60+ days'));
    $buckets[$bk] += $o['bal'];
}
unset($o);
if ($export) {
    csv_download('outstanding-' . date('Ymd') . '.csv', ['Receipt', 'Client', 'Phone', 'Sale date', 'Age (days)', 'Total', 'Paid', 'Balance'],
        array_map(fn($o) => [$o['receipt_no'], $o['name'], $o['phone'], $o['sale_date'], $o['age'], $o['total'], $o['paid'], $o['bal']], $owing));
}
page_start('Reports', 'reports');
page_head('Reports', 'Unpaid balances, oldest first', '<a class="btn ghost" href="' . e(url('reports', ['r' => 'outstanding', 'export' => 1])) . '">' . icon('download', 16) . ' Export CSV</a>');
tab_nav($tabs, $tab);
?>
<div class="grid g4">
  <?php $i = 0; foreach ($buckets as $l => $v): $i++; ?>
    <div class="card kpi <?= $i >= 3 && $v > 0 ? 'red' : ($i === 1 ? '' : 'hl') ?>"><span class="lbl"><?= e($l) ?></span><span class="val"><?= money($v) ?></span></div>
  <?php endforeach; ?>
</div>
<div class="card">
  <div class="card-h"><h2><?= icon('clock') ?> Clients who owe</h2><b class="gold-t"><?= money($sumOwing) ?> total</b></div>
  <?php if (!$owing): ?><div class="empty"><h2>All settled 🎉</h2><p>No outstanding balances.</p></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl stack"><thead><tr><th>Client</th><th>Receipt</th><th>Sale date</th><th class="r">Age</th><th class="r">Total</th><th class="r">Balance</th><th></th></tr></thead><tbody>
    <?php foreach ($owing as $o): $wa = wa_number((string) $o['phone']); ?><tr>
      <td data-label="Client"><b><?= e($o['name']) ?></b><span class="sub-line"><?= e($o['phone']) ?></span></td>
      <td data-label="Receipt"><a href="<?= e(url('sale_view', ['id' => $o['id']])) ?>"><?= e($o['receipt_no']) ?></a></td>
      <td data-label="Date"><?= e(fmt_date($o['sale_date'], 'd M Y')) ?></td>
      <td data-label="Age" class="r <?= $o['age'] > 30 ? 'red-t' : '' ?>"><?= (int) $o['age'] ?> d</td>
      <td data-label="Total" class="r"><?= money($o['total']) ?></td>
      <td data-label="Balance" class="r gold-t"><b><?= money($o['bal']) ?></b></td>
      <td class="act"><?php if ($wa): ?><a class="btn sm ghost" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hello ' . $o['name'] . ', this is ' . setting('studio_name', 'DotCliq Studio') . '. A friendly reminder of your balance of ' . money($o['bal']) . ' (' . $o['receipt_no'] . '). Thank you!') ?>"><?= icon('phone', 13) ?> Remind</a><?php endif; ?><a class="btn sm ghost" href="<?= e(url('sale_view', ['id' => $o['id']])) ?>">Open</a></td>
    </tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php page_end();

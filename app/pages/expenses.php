<?php
declare(strict_types=1);

require_can('expenses');

const EXPENSE_CATEGORIES = ['Rent', 'Salaries & wages', 'Equipment & gear', 'Props & backdrops', 'Printing & albums', 'Internet & airtime', 'Electricity & water', 'Transport', 'Marketing', 'Software & subscriptions', 'Repairs & maintenance', 'Licences & permits', 'Other'];

if (is_post()) {
    $action = post('action');
    $id = (int) post('id');
    if ($action === 'save') {
        $date = post('expense_date');
        $amount = round(num(post('amount')), 2);
        $cat = mb_substr(post('category'), 0, 80);
        if (!valid_date($date) || $amount <= 0 || $cat === '') {
            flash('Enter a date, category and an amount greater than zero.', 'err');
        } else {
            $data = [$date, $cat, mb_substr(post('description'), 0, 255) ?: null, $amount, pick(post('method'), array_keys(PAY_METHODS), 'cash')];
            if ($id > 0) {
                q('UPDATE expenses SET expense_date=?, category=?, description=?, amount=?, method=? WHERE id=?', [...$data, $id]);
                log_activity('expense_edit', $cat . ' ' . money($amount));
                flash('Expense updated.');
            } else {
                q('INSERT INTO expenses (expense_date, category, description, amount, method, recorded_by, created_at) VALUES (?,?,?,?,?,?,?)', [...$data, user()['id'], date('Y-m-d H:i:s')]);
                log_activity('expense_add', $cat . ' ' . money($amount));
                flash('Expense recorded.');
            }
        }
    } elseif ($action === 'delete' && can('void') && $id > 0) {
        q('DELETE FROM expenses WHERE id = ?', [$id]);
        log_activity('expense_delete', '#' . $id);
        flash('Expense deleted.');
    }
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? url('expenses')));
    exit;
}

[$from, $to, $key] = period_current();
$params = [];
$cond = range_cond('e.expense_date', $from, $to, $params);
$catF = get('category');
if ($catF !== '') {
    $cond .= ' AND e.category = ?';
    $params[] = $catF;
}
$list = rows("SELECT e.*, u.name AS who FROM expenses e LEFT JOIN users u ON u.id = e.recorded_by WHERE 1=1 $cond ORDER BY e.expense_date DESC, e.id DESC LIMIT 500", $params);
$total = array_sum(array_map(fn($r) => (float) $r['amount'], $list));
$byCat = [];
foreach ($list as $r) {
    $byCat[$r['category']] = ($byCat[$r['category']] ?? 0) + (float) $r['amount'];
}
arsort($byCat);
$allCats = array_values(array_unique(array_merge(EXPENSE_CATEGORIES, array_column(rows('SELECT DISTINCT category FROM expenses'), 'category'))));

if (get('export') === '1') {
    csv_download('expenses-' . date('Ymd') . '.csv', ['Date', 'Category', 'Description', 'Amount', 'Method', 'Recorded by'],
        array_map(fn($r) => [$r['expense_date'], $r['category'], $r['description'], $r['amount'], $r['method'], $r['who']], $list));
}

page_start('Expenses', 'expenses');
page_head('Expenses', 'Operating costs — deducted from collections to get net profit',
    '<a class="btn ghost" href="' . e(url('expenses', ['period' => $key, 'from' => $from, 'to' => $to, 'category' => $catF, 'export' => 1])) . '">' . icon('download', 16) . ' Export CSV</a>'
    . '<button class="btn" data-open="expDlg" data-fill=\'' . e(json_encode(['id' => '', 'expense_date' => date('Y-m-d'), 'category' => '', 'description' => '', 'amount' => '', 'method' => 'cash', '_title' => 'Add expense'])) . '\'>' . icon('plus', 16) . ' Add expense</button>');
period_filter('expenses', $catF !== '' ? ['category' => $catF] : [], true);
?>
<div class="grid g-main">
  <div class="card">
    <div class="card-h"><h2><?= icon('card') ?> Expense entries</h2><form method="get" action="index.php" class="row"><input type="hidden" name="p" value="expenses"><input type="hidden" name="period" value="<?= e($key) ?>"><input type="hidden" name="from" value="<?= e((string) get('from')) ?>"><input type="hidden" name="to" value="<?= e((string) get('to')) ?>">
      <select name="category" onchange="this.form.submit()" style="width:auto" aria-label="Category"><option value="">All categories</option><?php foreach ($allCats as $c): ?><option <?= $catF === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></form></div>
    <?php if (!$list): ?><div class="empty"><p>No expenses recorded for this timeframe.</p></div><?php else: ?>
    <div class="tbl-wrap"><table class="tbl stack">
      <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Method</th><th class="r">Amount</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($list as $r): ?>
        <tr>
          <td data-label="Date" class="nowrap"><?= e(fmt_date($r['expense_date'], 'd M Y')) ?></td>
          <td data-label="Category"><?= e($r['category']) ?></td>
          <td data-label="Description"><?= e($r['description'] ?: '—') ?><span class="sub-line"><?= e($r['who']) ?></span></td>
          <td data-label="Method"><?= e(PAY_METHODS[$r['method']] ?? $r['method']) ?></td>
          <td data-label="Amount" class="r red-t"><b><?= money($r['amount']) ?></b></td>
          <td class="act">
            <button class="btn sm ghost" data-open="expDlg" data-fill='<?= e(json_encode(['id' => $r['id'], 'expense_date' => $r['expense_date'], 'category' => $r['category'], 'description' => $r['description'], 'amount' => $r['amount'], 'method' => $r['method'], '_title' => 'Edit expense'])) ?>' aria-label="Edit"><?= icon('edit', 14) ?></button>
            <?php if (can('void')): ?><form method="post" data-confirm="Delete this expense?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn sm danger" type="submit" aria-label="Delete"><?= icon('trash', 14) ?></button></form><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="4">Total (<?= count($list) ?> entries)</td><td class="r"><?= money($total) ?></td><td></td></tr></tfoot>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <div class="card-h"><h2>By category</h2></div>
    <div class="chart" data-type="donut" data-chart='<?= chart_json(['items' => array_map(fn($k, $v) => ['label' => $k, 'value' => $v], array_keys($byCat), array_values($byCat)), 'prefix' => '', 'center' => 'spent']) ?>'></div>
  </div>
</div>

<dialog id="expDlg">
  <div class="dlg-h"><h2 data-title>Expense</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x', 18) ?></button></div>
  <form method="post" class="dlg-b">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <div class="grid2">
      <label class="fld"><span>Date *</span><input type="date" name="expense_date" required max="<?= date('Y-m-d') ?>"></label>
      <label class="fld"><span>Amount (<?= e(setting('currency', 'KSh')) ?>) *</span><input name="amount" inputmode="decimal" required></label>
      <label class="fld"><span>Category *</span><input name="category" list="expCats" required maxlength="80" placeholder="Choose or type"></label>
      <label class="fld"><span>Paid by</span><select name="method"><?php foreach (PAY_METHODS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
    </div>
    <datalist id="expCats"><?php foreach ($allCats as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?></datalist>
    <label class="fld"><span>Description</span><input name="description" maxlength="255" placeholder="What was it for?"></label>
    <div class="form-actions"><button class="btn" type="submit">Save expense</button><button class="btn ghost" type="button" data-close>Cancel</button></div>
  </form>
</dialog>
<?php page_end();

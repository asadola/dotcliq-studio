<?php
declare(strict_types=1);

require_can('sales');

$packages = rows('SELECT id, name, price FROM packages WHERE active = 1 ORDER BY category, name');
$clients = rows('SELECT id, name, phone FROM clients ORDER BY name');
$sellers = staff_list(['admin', 'manager', 'frontdesk']);
$photogs = staff_list(['admin', 'manager', 'photographer']);
$editors = staff_list(['admin', 'manager', 'editor']);
$me = (int) user()['id'];

/* Optional pre-fill from a booking or a client */
$booking = null;
$bookingId = (int) get('booking');
if ($bookingId) {
    $booking = row('SELECT * FROM bookings WHERE id = ?', [$bookingId]);
    if ($booking && $booking['sale_id']) {
        flash('That booking already has a sale.', 'warn');
        redirect('sale_view', ['id' => (int) $booking['sale_id']]);
    }
}
$preClient = (int) ($booking['client_id'] ?? get('client'));
$prefill = [];
if ($booking && $booking['package_id']) {
    $pk = row('SELECT id, name, price FROM packages WHERE id = ?', [$booking['package_id']]);
    if ($pk) {
        $prefill[] = ['pkg' => (int) $pk['id'], 'desc' => $pk['name'], 'qty' => 1, 'price' => (float) $pk['price']];
    }
}

if (is_post()) {
    $errors = [];

    // Client
    $mode = pick(post('client_mode'), ['existing', 'new'], 'existing');
    $clientId = 0;
    if ($mode === 'new') {
        if (post('new_name') === '') {
            $errors[] = 'Enter the client\'s name.';
        }
    } else {
        $clientId = (int) post('client_id');
        if (!$clientId || !val('SELECT id FROM clients WHERE id = ?', [$clientId])) {
            $errors[] = 'Choose a client or switch to "New client".';
        }
    }

    // Items
    $items = [];
    $descs = $_POST['desc'] ?? [];
    $qtys = $_POST['qty'] ?? [];
    $prices = $_POST['price'] ?? [];
    $pkgs = $_POST['pkg'] ?? [];
    foreach ((array) $descs as $i => $d) {
        $d = trim((string) $d);
        $qty = max(1, (int) ($qtys[$i] ?? 1));
        $price = num($prices[$i] ?? 0);
        if ($d === '' && $price <= 0) {
            continue;
        }
        if ($d === '' || $price < 0) {
            $errors[] = 'Each line needs a description and a valid price.';
            break;
        }
        $items[] = ['pkg' => (int) ($pkgs[$i] ?? 0) ?: null, 'desc' => mb_substr($d, 0, 200), 'qty' => $qty, 'price' => $price, 'line' => round($qty * $price, 2)];
    }
    if (!$items) {
        $errors[] = 'Add at least one service or package.';
    }
    $subtotal = round(array_sum(array_column($items, 'line')), 2);
    $discount = min($subtotal, max(0, num(post('discount'))));
    $total = round($subtotal - $discount, 2);
    $deposit = max(0, num(post('deposit')));
    if ($deposit > $total + 0.004) {
        $errors[] = 'The deposit cannot be more than the sale total.';
    }
    $method = pick(post('method'), array_keys(PAY_METHODS), 'cash');
    $saleDate = valid_date(post('sale_date')) ? post('sale_date') : date('Y-m-d');
    $soldBy = (can('void') && (int) post('sold_by')) ? (int) post('sold_by') : $me;

    // Optional shoot + job
    $withShoot = post('with_shoot') === '1' && !$booking;
    $shootDate = post('shoot_date');
    if ($withShoot && !valid_date($shootDate)) {
        $errors[] = 'Choose a valid shoot date or untick "Schedule a shoot".';
    }
    $withJob = post('with_job') === '1';
    $jobDue = post('job_due');
    if ($withJob && $jobDue !== '' && !valid_date($jobDue)) {
        $errors[] = 'Editing due date is not valid.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($mode === 'new') {
                [$clientId] = find_or_create_client(post('new_name'), post('new_phone'), post('new_email'));
            }
            $now = date('Y-m-d H:i:s');
            q('INSERT INTO sales (client_id, booking_id, sold_by, sale_date, subtotal, discount, total, status, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$clientId, $booking['id'] ?? null, $soldBy, $saleDate, $subtotal, $discount, $total, 'active', post('notes') ?: null, $now]);
            $saleId = last_id();
            q('UPDATE sales SET receipt_no = ? WHERE id = ?', [make_receipt_no($saleId), $saleId]);

            foreach ($items as $it) {
                q('INSERT INTO sale_items (sale_id, package_id, description, qty, unit_price, line_total) VALUES (?,?,?,?,?,?)',
                    [$saleId, $it['pkg'], $it['desc'], $it['qty'], $it['price'], $it['line']]);
            }
            if ($deposit > 0) {
                q('INSERT INTO payments (sale_id, amount, method, reference, paid_at, received_by, note, created_at) VALUES (?,?,?,?,?,?,?,?)',
                    [$saleId, $deposit, $method, mb_substr(post('reference'), 0, 80) ?: null, $saleDate . ' ' . date('H:i:s'), $me, $deposit >= $total - 0.004 ? 'Full payment' : 'Deposit', $now]);
            }

            if ($booking) {
                q('UPDATE bookings SET sale_id = ? WHERE id = ?', [$saleId, $booking['id']]);
            } elseif ($withShoot) {
                $st = post('shoot_start');
                q('INSERT INTO bookings (client_id, package_id, title, shoot_date, start_time, end_time, location, assigned_to, status, notes, sale_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$clientId, $items[0]['pkg'], mb_substr($items[0]['desc'], 0, 160), $shootDate, valid_time($st) ? $st : null, null,
                     mb_substr(post('shoot_location'), 0, 200) ?: null, (int) post('shoot_staff') ?: null, 'confirmed', null, $saleId, $me, $now]);
                q('UPDATE sales SET booking_id = ? WHERE id = ?', [last_id(), $saleId]);
            }

            if ($withJob) {
                q('INSERT INTO jobs (client_id, sale_id, title, job_type, assigned_to, status, priority, due_date, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$clientId, $saleId, mb_substr('Edit: ' . $items[0]['desc'], 0, 160), pick(post('job_type'), array_keys(JOB_TYPES), 'retouch'), (int) post('job_staff') ?: null,
                     'queued', 'normal', $jobDue ?: null, $me, $now]);
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        log_activity('sale_add', make_receipt_no($saleId) . ' ' . money($total));
        flash('Sale logged — ' . make_receipt_no($saleId) . '.');
        redirect('sale_view', ['id' => $saleId]);
    }
    foreach ($errors as $er) {
        flash($er, 'err');
    }
    $preClient = (int) post('client_id');
    $prefill = [];
    foreach ((array) $descs as $i => $d) {
        if (trim((string) $d) !== '') {
            $prefill[] = ['pkg' => (int) ($pkgs[$i] ?? 0), 'desc' => (string) $d, 'qty' => (int) ($qtys[$i] ?? 1), 'price' => num($prices[$i] ?? 0)];
        }
    }
}

page_start('Log sale', 'sales');
page_head('Log a sale', 'Record the services sold and any deposit paid. Balances can be updated later.');
?>
<form method="post" id="saleForm" data-packages='<?= e(json_encode($packages, JSON_NUMERIC_CHECK)) ?>' data-prefill='<?= e(json_encode($prefill)) ?>' data-cur="<?= e(setting('currency', 'KSh')) ?>">
<?= csrf_field() ?>
<div class="grid g-main">
  <div>
    <div class="card stack">
      <div class="card-h"><h2><?= icon('users') ?> Client</h2>
        <div class="row">
          <label class="check"><input type="radio" name="client_mode" value="existing" <?= post('client_mode') !== 'new' ? 'checked' : '' ?>> Existing</label>
          <label class="check"><input type="radio" name="client_mode" value="new" <?= post('client_mode') === 'new' ? 'checked' : '' ?>> New client</label>
        </div>
      </div>
      <div id="clientExisting" <?= post('client_mode') === 'new' ? 'style="display:none"' : '' ?>>
        <select name="client_id" aria-label="Client">
          <option value="">Select client…</option>
          <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $preClient === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['phone'] ? ' — ' . e($c['phone']) : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div id="clientNew" class="grid2" <?= post('client_mode') === 'new' ? '' : 'style="display:none"' ?>>
        <label class="fld"><span>Name *</span><input name="new_name" value="<?= e(post('new_name')) ?>" maxlength="140"></label>
        <label class="fld"><span>Phone</span><input name="new_phone" value="<?= e(post('new_phone')) ?>" inputmode="tel" maxlength="40"></label>
        <label class="fld" style="grid-column:1/-1"><span>Email (optional)</span><input type="email" name="new_email" value="<?= e(post('new_email')) ?>" maxlength="160"></label>
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h2><?= icon('tag') ?> Services sold</h2><button type="button" class="btn sm ghost" id="addItem"><?= icon('plus', 14) ?> Add line</button></div>
      <div class="tbl-wrap"><table class="tbl items-t" style="min-width:560px">
        <thead><tr><th style="width:34%">Package</th><th>Description</th><th style="width:70px">Qty</th><th style="width:120px">Unit price</th><th class="r" style="width:100px">Line total</th><th style="width:36px"></th></tr></thead>
        <tbody id="itemRows"></tbody>
      </table></div>
      <template id="itemTpl"><table><tr class="item">
        <td><select name="pkg[]" aria-label="Package"><option value="">Custom item</option></select></td>
        <td><input name="desc[]" maxlength="200" placeholder="Description" aria-label="Description"></td>
        <td><input name="qty[]" type="number" min="1" value="1" inputmode="numeric" aria-label="Quantity"></td>
        <td><input name="price[]" inputmode="decimal" placeholder="0" aria-label="Unit price"></td>
        <td class="r"><b class="lt">0</b></td>
        <td><button type="button" class="icon-btn rm" style="width:32px;height:32px" aria-label="Remove line"><?= icon('x', 14) ?></button></td>
      </tr></table></template>
    </div>

    <div class="card stack">
      <div class="card-h"><h2><?= icon('layers') ?> Workflow (optional)</h2></div>
      <?php if (!$booking): ?>
      <label class="check"><input type="checkbox" id="withShoot" name="with_shoot" value="1" <?= post('with_shoot') === '1' ? 'checked' : '' ?>> Schedule a shoot for this sale</label>
      <div id="shootBox" class="grid2" <?= post('with_shoot') === '1' ? '' : 'style="display:none"' ?>>
        <label class="fld"><span>Shoot date</span><input type="date" name="shoot_date" value="<?= e(post('shoot_date')) ?>"></label>
        <label class="fld"><span>Start time</span><input type="time" name="shoot_start" value="<?= e(post('shoot_start')) ?>"></label>
        <label class="fld"><span>Location</span><input name="shoot_location" value="<?= e(post('shoot_location')) ?>" maxlength="200" placeholder="Studio / venue"></label>
        <label class="fld"><span>Photographer</span><select name="shoot_staff"><option value="">Unassigned</option><?php foreach ($photogs as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
      </div>
      <?php else: ?>
      <p class="muted">Linked to booking: <b><?= e($booking['title']) ?></b> on <?= e(fmt_date($booking['shoot_date'])) ?>.</p>
      <?php endif; ?>
      <label class="check"><input type="checkbox" name="with_job" value="1" <?= post('with_job') === '1' ? 'checked' : '' ?>> Add to the editing / delivery queue</label>
      <div class="grid2">
        <label class="fld"><span>Job type</span><select name="job_type"><?php foreach (JOB_TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="fld"><span>Editor</span><select name="job_staff"><option value="">Unassigned</option><?php foreach ($editors as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
        <label class="fld"><span>Delivery due</span><input type="date" name="job_due" value="<?= e(post('job_due')) ?>"></label>
      </div>
    </div>
  </div>

  <div>
    <div class="card stack" style="position:sticky;top:76px">
      <div class="card-h"><h2><?= icon('card') ?> Payment</h2></div>
      <div class="grid2">
        <label class="fld"><span>Sale date</span><input type="date" name="sale_date" value="<?= e(post('sale_date', date('Y-m-d'))) ?>" max="<?= date('Y-m-d') ?>"></label>
        <label class="fld"><span>Discount</span><input id="discount" name="discount" inputmode="decimal" value="<?= e(post('discount')) ?>" placeholder="0"></label>
      </div>
      <div class="sum">
        <div class="l"><span class="muted">Subtotal</span><b id="subT">—</b></div>
        <div class="l t"><span>Grand total</span><b id="totT" class="gold-t">—</b></div>
      </div>
      <label class="fld"><span>Deposit / amount paid now</span><input id="deposit" name="deposit" inputmode="decimal" value="<?= e(post('deposit')) ?>" placeholder="0"></label>
      <div id="depWarn" class="alert a-err" style="display:none;margin:0">Deposit is more than the total.</div>
      <div class="grid2">
        <label class="fld"><span>Method</span><select name="method"><?php foreach (PAY_METHODS as $k => $l): ?><option value="<?= e($k) ?>" <?= post('method', 'mpesa') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
        <label class="fld"><span>Reference / M-Pesa code</span><input name="reference" value="<?= e(post('reference')) ?>" maxlength="80" placeholder="e.g. SJK3L9XYZ"></label>
      </div>
      <div class="sum"><div class="l"><span class="muted">Balance due</span><b id="balT">—</b></div></div>
      <?php if (can('void')): ?>
      <label class="fld"><span>Sold by</span><select name="sold_by"><?php foreach ($sellers as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $s['id'] === $me ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></label>
      <?php endif; ?>
      <label class="fld"><span>Notes</span><textarea name="notes" placeholder="Anything to remember about this sale"><?= e(post('notes')) ?></textarea></label>
      <button class="btn lg gold block" type="submit"><?= icon('check', 18) ?> Save sale</button>
    </div>
  </div>
</div>
</form>
<?php page_end();

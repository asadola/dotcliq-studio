<?php
declare(strict_types=1);

require_can('assign');
require_can('bookings');

$id = (int) get('id');
$b = $id ? row('SELECT * FROM bookings WHERE id = ?', [$id]) : null;
if ($id && !$b) {
    flash('Booking not found.', 'err');
    redirect('bookings');
}

if (is_post()) {
    if (post('action') === 'delete' && $b && can('void')) {
        q('DELETE FROM bookings WHERE id = ?', [$id]);
        q('UPDATE sales SET booking_id = NULL WHERE booking_id = ?', [$id]);
        log_activity('booking_delete', '#' . $id);
        flash('Booking deleted.');
        redirect('bookings');
    }

    $errors = [];
    $clientSel = post('client_id');
    $clientId = 0;
    if ($clientSel === 'new') {
        if (post('new_name') === '') {
            $errors[] = 'Enter the new client\'s name.';
        }
    } else {
        $clientId = (int) $clientSel;
        if (!$clientId || !val('SELECT id FROM clients WHERE id = ?', [$clientId])) {
            $errors[] = 'Choose a client.';
        }
    }
    $title = post('title');
    $date = post('shoot_date');
    $start = post('start_time');
    $end = post('end_time');
    if ($title === '') $errors[] = 'Enter a booking title (e.g. "Birthday shoot").';
    if (!valid_date($date)) $errors[] = 'Choose a valid shoot date.';
    if ($start !== '' && !valid_time($start)) $errors[] = 'Start time is not valid.';
    if ($end !== '' && !valid_time($end)) $errors[] = 'End time is not valid.';
    if ($start !== '' && $end !== '' && $end <= $start) $errors[] = 'End time must be after the start time.';

    if (!$errors) {
        if ($clientSel === 'new') {
            [$clientId] = find_or_create_client(post('new_name'), post('new_phone'));
        }
        $pkg = (int) post('package_id') ?: null;
        $staffId = (int) post('assigned_to') ?: null;
        $status = pick(post('status'), array_keys(BOOKING_STATUSES), 'pending');
        $data = [$clientId, $pkg, mb_substr($title, 0, 160), $date, $start ?: null, $end ?: null, mb_substr(post('location'), 0, 200) ?: null, $staffId, $status, post('notes') ?: null];
        if ($b) {
            q('UPDATE bookings SET client_id=?, package_id=?, title=?, shoot_date=?, start_time=?, end_time=?, location=?, assigned_to=?, status=?, notes=? WHERE id=?', [...$data, $id]);
            log_activity('booking_edit', $title . ' ' . $date);
            flash('Booking updated.');
        } else {
            q('INSERT INTO bookings (client_id, package_id, title, shoot_date, start_time, end_time, location, assigned_to, status, notes, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [...$data, user()['id'], date('Y-m-d H:i:s')]);
            log_activity('booking_add', $title . ' ' . $date);
            flash('Booking created.');
        }
        redirect('bookings', ['v' => 'cal', 'm' => substr($date, 0, 7)]);
    }
    foreach ($errors as $er) {
        flash($er, 'err');
    }
    $b = array_merge($b ?? [], [
        'client_id' => $clientSel, 'package_id' => post('package_id'), 'title' => $title, 'shoot_date' => $date, 'start_time' => $start, 'end_time' => $end,
        'location' => post('location'), 'assigned_to' => post('assigned_to'), 'status' => post('status'), 'notes' => post('notes'),
    ]);
}

$clients = rows('SELECT id, name, phone FROM clients ORDER BY name');
$packages = rows('SELECT id, name FROM packages WHERE active = 1 ORDER BY name');
$staff = staff_list(['admin', 'manager', 'photographer']);
$preClient = (int) get('client');
$v = fn(string $k, $d = '') => $b[$k] ?? $d;

page_start($b && isset($b['id']) ? 'Edit booking' : 'New booking', 'bookings');
page_head($b && isset($b['id']) ? 'Edit booking' : 'New booking', 'Schedule a shoot and assign a photographer');
?>
<form method="post" class="card stack" style="max-width:820px">
  <?= csrf_field() ?>
  <div class="grid2">
    <label class="fld"><span>Client *</span>
      <select name="client_id" data-new="#newClient" required>
        <option value="">Select client…</option>
        <option value="new">＋ New client…</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (string) ($v('client_id') ?: $preClient) === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= $c['phone'] ? ' — ' . e($c['phone']) : '' ?></option><?php endforeach; ?>
      </select>
    </label>
    <label class="fld"><span>Package / service</span>
      <select name="package_id"><option value="">— Not set —</option><?php foreach ($packages as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (string) $v('package_id') === (string) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
    </label>
  </div>
  <div id="newClient" class="grid2" style="display:none">
    <label class="fld"><span>New client name *</span><input name="new_name" value="<?= e(post('new_name')) ?>" maxlength="140"></label>
    <label class="fld"><span>Phone</span><input name="new_phone" value="<?= e(post('new_phone')) ?>" inputmode="tel" maxlength="40"></label>
  </div>
  <label class="fld"><span>Title *</span><input name="title" value="<?= e($v('title')) ?>" maxlength="160" placeholder="e.g. Maternity shoot, Wedding – Kamau & Wanjiru" required></label>
  <div class="grid g3">
    <label class="fld"><span>Shoot date *</span><input type="date" name="shoot_date" value="<?= e($v('shoot_date', get('date') ?: date('Y-m-d'))) ?>" required></label>
    <label class="fld"><span>Start time</span><input type="time" name="start_time" value="<?= e(substr((string) $v('start_time'), 0, 5)) ?>"></label>
    <label class="fld"><span>End time</span><input type="time" name="end_time" value="<?= e(substr((string) $v('end_time'), 0, 5)) ?>"></label>
  </div>
  <div class="grid2">
    <label class="fld"><span>Location</span><input name="location" value="<?= e($v('location')) ?>" maxlength="200" placeholder="Studio, venue or address"></label>
    <label class="fld"><span>Assigned to</span>
      <select name="assigned_to"><option value="">Unassigned</option><?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (string) $v('assigned_to') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e(role_label($s['role'])) ?>)</option><?php endforeach; ?></select>
    </label>
  </div>
  <label class="fld"><span>Status</span>
    <select name="status"><?php foreach (BOOKING_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('status', 'pending') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  </label>
  <label class="fld"><span>Notes</span><textarea name="notes" placeholder="Outfits, special requests, deposit agreed…"><?= e($v('notes')) ?></textarea></label>
  <div class="form-actions">
    <button class="btn" type="submit"><?= $id ? 'Save changes' : 'Create booking' ?></button>
    <a class="btn ghost" href="<?= e(url('bookings')) ?>">Cancel</a>
  </div>
</form>
<?php if ($id && can('void')): ?>
<form method="post" data-confirm="Delete this booking permanently?" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger sm" type="submit"><?= icon('trash', 14) ?> Delete booking</button></form>
<?php endif; ?>
<?php page_end();

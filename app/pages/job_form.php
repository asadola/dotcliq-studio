<?php
declare(strict_types=1);

require_can('jobs');
$me = (int) user()['id'];

$id = (int) get('id');
$job = $id ? row('SELECT * FROM jobs WHERE id = ?', [$id]) : null;
if ($id && !$job) {
    flash('Job not found.', 'err');
    redirect('jobs');
}
$full = can('assign');                 // full edit rights
$own = $job && (int) $job['assigned_to'] === $me;
if (!$full && !$own) {
    require_can('assign'); // renders access denied
}

$saleId = (int) get('sale');
$sale = $saleId ? row('SELECT * FROM sales WHERE id = ?', [$saleId]) : null;

if (is_post()) {
    if (post('action') === 'delete' && $job && can('void')) {
        q('DELETE FROM jobs WHERE id = ?', [$id]);
        log_activity('job_delete', $job['title']);
        flash('Job deleted.');
        redirect('jobs');
    }

    $status = pick(post('status'), array_keys(JOB_STATUSES), 'queued');
    $link = post('delivery_link');
    $errors = [];
    if ($link !== '' && !preg_match('#^https?://#i', $link)) {
        $errors[] = 'Delivery link must start with http:// or https://';
    }

    if ($full) {
        $title = post('title');
        $clientId = (int) post('client_id');
        $due = post('due_date');
        if ($title === '') $errors[] = 'Enter a job title.';
        if (!$clientId || !val('SELECT id FROM clients WHERE id = ?', [$clientId])) $errors[] = 'Choose a client.';
        if ($due !== '' && !valid_date($due)) $errors[] = 'Due date is not valid.';
    }

    if (!$errors) {
        $deliveredAt = $status === 'delivered' ? ($job['delivered_at'] ?: date('Y-m-d H:i:s')) : null;
        if ($job && !$full) {
            q('UPDATE jobs SET status=?, delivery_link=?, notes=?, delivered_at=? WHERE id=?', [$status, $link ?: null, post('notes') ?: null, $deliveredAt, $id]);
        } else {
            $data = [$clientId, (int) post('sale_id') ?: null, mb_substr($title, 0, 160), pick(post('job_type'), array_keys(JOB_TYPES), 'retouch'), (int) post('assigned_to') ?: null,
                $status, pick(post('priority'), ['normal', 'urgent'], 'normal'), $due ?: null, $link ?: null, post('notes') ?: null, $deliveredAt];
            if ($job) {
                q('UPDATE jobs SET client_id=?, sale_id=?, title=?, job_type=?, assigned_to=?, status=?, priority=?, due_date=?, delivery_link=?, notes=?, delivered_at=? WHERE id=?', [...$data, $id]);
            } else {
                q('INSERT INTO jobs (client_id, sale_id, title, job_type, assigned_to, status, priority, due_date, delivery_link, notes, delivered_at, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$data, $me, date('Y-m-d H:i:s')]);
            }
        }
        log_activity($job ? 'job_edit' : 'job_add', $title ?? $job['title']);
        flash($job ? 'Job updated.' : 'Job added to the queue.');
        redirect('jobs');
    }
    foreach ($errors as $er) {
        flash($er, 'err');
    }
    $job = array_merge($job ?? [], $_POST);
}

$clients = rows('SELECT id, name FROM clients ORDER BY name');
$editors = staff_list(['admin', 'manager', 'editor', 'photographer']);
$v = fn(string $k, $d = '') => $job[$k] ?? $d;
$preClient = (int) ($sale['client_id'] ?? get('client'));
$isEdit = $job && isset($job['id']);

page_start($isEdit ? 'Edit job' : 'Add job', 'jobs');
page_head($isEdit ? 'Edit job' : 'Add job', $full ? 'Add work to the editing and delivery queue' : 'Update progress and add the delivery link');
?>
<form method="post" class="card stack" style="max-width:820px">
  <?= csrf_field() ?>
  <?php if ($full): ?>
  <input type="hidden" name="sale_id" value="<?= (int) ($v('sale_id') ?: $saleId) ?>">
  <label class="fld"><span>Job title *</span><input name="title" value="<?= e($v('title', $sale ? 'Edit: ' . ($sale['receipt_no'] ?? '') : '')) ?>" maxlength="160" required placeholder="e.g. Retouch 25 portraits"></label>
  <div class="grid2">
    <label class="fld"><span>Client *</span><select name="client_id" required><option value="">Select…</option><?php foreach ($clients as $c): ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($v('client_id') ?: $preClient) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></label>
    <label class="fld"><span>Type</span><select name="job_type"><?php foreach (JOB_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('job_type') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="fld"><span>Assigned to</span><select name="assigned_to"><option value="">Unassigned</option><?php foreach ($editors as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (string) $v('assigned_to') === (string) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?> (<?= e(role_label($s['role'])) ?>)</option><?php endforeach; ?></select></label>
    <label class="fld"><span>Due date</span><input type="date" name="due_date" value="<?= e($v('due_date')) ?>"></label>
    <label class="fld"><span>Priority</span><select name="priority"><option value="normal">Normal</option><option value="urgent" <?= $v('priority') === 'urgent' ? 'selected' : '' ?>>Urgent</option></select></label>
  </div>
  <?php else: ?>
  <div class="card" style="background:var(--card2)"><b><?= e($job['title']) ?></b><div class="muted"><?= e(JOB_TYPES[$job['job_type']] ?? '') ?> · due <?= e(fmt_date($job['due_date'])) ?></div></div>
  <?php endif; ?>
  <div class="grid2">
    <label class="fld"><span>Status</span><select name="status"><?php foreach (JOB_STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('status', 'queued') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></label>
    <label class="fld"><span>Delivery link</span><input name="delivery_link" value="<?= e($v('delivery_link')) ?>" maxlength="255" placeholder="https://drive.google.com/…"></label>
  </div>
  <label class="fld"><span>Notes</span><textarea name="notes"><?= e($v('notes')) ?></textarea></label>
  <div class="form-actions"><button class="btn" type="submit"><?= $isEdit ? 'Save changes' : 'Add job' ?></button><a class="btn ghost" href="<?= e(url('jobs')) ?>">Cancel</a></div>
</form>
<?php if ($isEdit && can('void')): ?>
<form method="post" data-confirm="Delete this job?" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><button class="btn danger sm" type="submit"><?= icon('trash', 14) ?> Delete job</button></form>
<?php endif; ?>
<?php page_end();

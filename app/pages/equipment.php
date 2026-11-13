<?php
declare(strict_types=1);

require_can('equipment');

const EQUIP_STATUS = ['available' => 'Available', 'in_use' => 'In use', 'maintenance' => 'In maintenance', 'retired' => 'Retired'];

if (is_post()) {
    $action = post('action');
    $id = (int) post('id');
    if ($action === 'save') {
        $name = post('name');
        $pd = post('purchase_date');
        if ($name === '') {
            flash('Equipment name is required.', 'err');
        } else {
            $data = [mb_substr($name, 0, 140), mb_substr(post('category'), 0, 80) ?: null, mb_substr(post('serial_no'), 0, 80) ?: null,
                pick(post('status'), array_keys(EQUIP_STATUS), 'available'), valid_date($pd) ? $pd : null, post('cost') !== '' ? num(post('cost')) : null, mb_substr(post('notes'), 0, 255) ?: null];
            if ($id > 0) {
                q('UPDATE equipment SET name=?, category=?, serial_no=?, status=?, purchase_date=?, cost=?, notes=? WHERE id=?', [...$data, $id]);
                flash('Equipment updated.');
            } else {
                q('INSERT INTO equipment (name, category, serial_no, status, purchase_date, cost, notes, created_at) VALUES (?,?,?,?,?,?,?,?)', [...$data, date('Y-m-d H:i:s')]);
                flash('Equipment added.');
            }
            log_activity('equipment_save', $name);
        }
    } elseif ($action === 'delete' && can('void') && $id > 0) {
        q('DELETE FROM equipment WHERE id = ?', [$id]);
        log_activity('equipment_delete', '#' . $id);
        flash('Equipment removed.');
    }
    redirect('equipment');
}

$list = rows("SELECT * FROM equipment ORDER BY (status = 'retired'), category, name");
$value = array_sum(array_map(fn($r) => $r['status'] === 'retired' ? 0 : (float) $r['cost'], $list));

page_start('Equipment', 'equipment');
page_head('Equipment', count($list) . ' items · asset value ' . money($value),
    '<button class="btn" data-open="eqDlg" data-fill=\'' . e(json_encode(['id' => '', 'name' => '', 'category' => '', 'serial_no' => '', 'status' => 'available', 'purchase_date' => '', 'cost' => '', 'notes' => '', '_title' => 'Add equipment'])) . '\'>' . icon('plus', 16) . ' Add equipment</button>');
?>
<div class="card">
<?php if (!$list): ?><div class="empty"><h2>No equipment logged</h2><p>Keep track of cameras, lenses, lights and who has what.</p></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl stack">
    <thead><tr><th>Item</th><th>Category</th><th>Serial no.</th><th>Purchased</th><th class="r">Cost</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
      <tr>
        <td data-label="Item"><b><?= e($r['name']) ?></b><span class="sub-line"><?= e($r['notes']) ?></span></td>
        <td data-label="Category"><?= e($r['category'] ?: '—') ?></td>
        <td data-label="Serial"><?= e($r['serial_no'] ?: '—') ?></td>
        <td data-label="Purchased"><?= e(fmt_date($r['purchase_date'])) ?></td>
        <td data-label="Cost" class="r"><?= $r['cost'] !== null ? money($r['cost']) : '—' ?></td>
        <td data-label="Status"><?= badge($r['status'], EQUIP_STATUS[$r['status']] ?? null) ?></td>
        <td class="act">
          <button class="btn sm ghost" data-open="eqDlg" data-fill='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'category' => $r['category'], 'serial_no' => $r['serial_no'], 'status' => $r['status'], 'purchase_date' => $r['purchase_date'], 'cost' => $r['cost'], 'notes' => $r['notes'], '_title' => 'Edit equipment'])) ?>' aria-label="Edit"><?= icon('edit', 14) ?></button>
          <?php if (can('void')): ?><form method="post" data-confirm="Remove this item?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn sm danger" type="submit" aria-label="Delete"><?= icon('trash', 14) ?></button></form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>

<dialog id="eqDlg">
  <div class="dlg-h"><h2 data-title>Equipment</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x', 18) ?></button></div>
  <form method="post" class="dlg-b">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <label class="fld"><span>Name *</span><input name="name" required maxlength="140" placeholder="e.g. Canon EOS R6"></label>
    <div class="grid2">
      <label class="fld"><span>Category</span><input name="category" maxlength="80" placeholder="Camera, Lens, Light…"></label>
      <label class="fld"><span>Serial number</span><input name="serial_no" maxlength="80"></label>
      <label class="fld"><span>Status</span><select name="status"><?php foreach (EQUIP_STATUS as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="fld"><span>Purchase date</span><input type="date" name="purchase_date"></label>
      <label class="fld"><span>Cost (<?= e(setting('currency', 'KSh')) ?>)</span><input name="cost" inputmode="decimal"></label>
    </div>
    <label class="fld"><span>Notes</span><input name="notes" maxlength="255" placeholder="Condition, who has it…"></label>
    <div class="form-actions"><button class="btn" type="submit">Save</button><button class="btn ghost" type="button" data-close>Cancel</button></div>
  </form>
</dialog>
<?php page_end();

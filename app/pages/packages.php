<?php
declare(strict_types=1);

require_can('packages');

$cats = ['photo' => 'Photography', 'video' => 'Videography', 'combo' => 'Photo + Video', 'other' => 'Other'];

if (is_post()) {
    $action = post('action');
    $id = (int) post('id');
    if ($action === 'save') {
        $name = post('name');
        $price = num(post('price'));
        $cat = pick(post('category'), array_keys($cats), 'photo');
        if ($name === '' || $price < 0) {
            flash('Enter a package name and a valid price.', 'err');
        } elseif ($id > 0) {
            q('UPDATE packages SET name=?, category=?, price=?, description=?, active=? WHERE id=?', [mb_substr($name, 0, 140), $cat, $price, mb_substr(post('description'), 0, 255) ?: null, post('active') === '1' ? 1 : 0, $id]);
            log_activity('package_edit', $name);
            flash('Package updated.');
        } else {
            q('INSERT INTO packages (name, category, price, description, active, created_at) VALUES (?,?,?,?,?,?)', [mb_substr($name, 0, 140), $cat, $price, mb_substr(post('description'), 0, 255) ?: null, 1, date('Y-m-d H:i:s')]);
            log_activity('package_add', $name);
            flash('Package added.');
        }
    } elseif ($action === 'delete' && $id > 0) {
        if ((int) val('SELECT COUNT(*) FROM sale_items WHERE package_id = ?', [$id]) > 0) {
            q('UPDATE packages SET active = 0 WHERE id = ?', [$id]);
            flash('This package has been sold before, so it was deactivated instead of deleted.', 'warn');
        } else {
            q('DELETE FROM packages WHERE id = ?', [$id]);
            flash('Package deleted.');
        }
    }
    redirect('packages');
}

$list = rows('SELECT pk.*, (SELECT COUNT(*) FROM sale_items si WHERE si.package_id = pk.id) AS sold FROM packages pk ORDER BY pk.active DESC, pk.category, pk.name');

page_start('Packages', 'packages');
page_head('Packages & Services', 'Prices here pre-fill the sales form. You can still adjust a price per sale.',
    '<button class="btn" data-open="pkgDlg" data-fill=\'' . e(json_encode(['id' => '', 'name' => '', 'category' => 'photo', 'price' => '', 'description' => '', 'active' => 1, '_title' => 'Add package'])) . '\'>' . icon('plus', 16) . ' Add package</button>');
?>
<div class="card">
<?php if (!$list): ?><div class="empty"><h2>No packages yet</h2></div><?php else: ?>
  <div class="tbl-wrap"><table class="tbl stack">
    <thead><tr><th>Package</th><th>Type</th><th class="r">Price</th><th class="r">Times sold</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $p): ?>
      <tr>
        <td data-label="Package"><b><?= e($p['name']) ?></b><span class="sub-line"><?= e($p['description']) ?></span></td>
        <td data-label="Type"><?= e($cats[$p['category']] ?? $p['category']) ?></td>
        <td data-label="Price" class="r"><?= money($p['price']) ?></td>
        <td data-label="Sold" class="r"><?= (int) $p['sold'] ?></td>
        <td data-label="Status"><?= badge($p['active'] ? 'active' : 'inactive') ?></td>
        <td class="act">
          <button class="btn sm ghost" data-open="pkgDlg" data-fill='<?= e(json_encode(['id' => $p['id'], 'name' => $p['name'], 'category' => $p['category'], 'price' => $p['price'], 'description' => $p['description'], 'active' => $p['active'], '_title' => 'Edit package'])) ?>'><?= icon('edit', 14) ?> Edit</button>
          <form method="post" data-confirm="Delete this package?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn sm danger" type="submit"><?= icon('trash', 14) ?></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
<?php endif; ?>
</div>

<dialog id="pkgDlg">
  <div class="dlg-h"><h2 data-title>Package</h2><button class="icon-btn" data-close aria-label="Close"><?= icon('x', 18) ?></button></div>
  <form method="post" class="dlg-b">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="">
    <label class="fld"><span>Package name *</span><input name="name" required maxlength="140"></label>
    <div class="grid2">
      <label class="fld"><span>Type</span><select name="category"><?php foreach ($cats as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
      <label class="fld"><span>Price (<?= e(setting('currency', 'KSh')) ?>) *</span><input name="price" inputmode="decimal" required></label>
    </div>
    <label class="fld"><span>Description</span><input name="description" maxlength="255"></label>
    <label class="check"><input type="checkbox" name="active" value="1" checked> Active (shown on the sales form)</label>
    <div class="form-actions"><button class="btn" type="submit">Save package</button><button class="btn ghost" type="button" data-close>Cancel</button></div>
  </form>
</dialog>
<?php page_end();

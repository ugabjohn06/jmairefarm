<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

if (is_post()) {
    csrf_check();
    $id = (int) post('id');
    $action = post('action');
    if ($action === 'save') {
        $name = post('name');
        $price = round((float) post('price'), 2);
        // Blank stock means unlimited (extra rice, mattresses you always have)
        $stockIn = trim((string) post('daily_stock'));
        $stock = $stockIn === '' ? null : (int) $stockIn;
        if (mb_strlen($name) < 2 || $price < 0 || ($stockIn !== '' && (!ctype_digit($stockIn)))) {
            flash('error', 'Enter a name, a valid price, and a whole number (or leave blank) for available per day.');
            redirect('admin/addons.php' . ($id ? '?edit=' . $id : ''));
        }
        $vals = [$name, post('description') ?: null, $price, post('unit_label') ?: 'each', $stock, post('is_active') ? 1 : 0];
        if ($id) {
            db_exec('UPDATE addons SET name=?, description=?, price=?, unit_label=?, daily_stock=?, is_active=? WHERE id=?', [...$vals, $id]);
            audit('addon.update', 'addon', $id, $name . ' ' . money($price) . ($stock === null ? ', unlimited' : ", $stock per day"));
        } else {
            db_exec('INSERT INTO addons (name, description, price, unit_label, daily_stock, is_active) VALUES (?,?,?,?,?,?)', $vals);
            audit('addon.create', 'addon', db_insert_id(), $name . ' ' . money($price));
        }
        flash('success', $name . ' saved.');
    } elseif ($action === 'delete' && $id) {
        // Old bookings keep their own copy of the price, so deleting is safe
        $name = db_value('SELECT name FROM addons WHERE id = ?', [$id]);
        db_exec('DELETE FROM addons WHERE id = ?', [$id]);
        audit('addon.delete', 'addon', $id, (string) $name);
        flash('success', 'Add-on deleted.');
    }
    redirect('admin/addons.php');
}

$addons = db_all('SELECT * FROM addons ORDER BY is_active DESC, name');
$edit = get('edit') ? db_one('SELECT * FROM addons WHERE id = ?', [(int) get('edit')]) : null;
$pageTitle = 'Add-ons';
$activeAdmin = 'addons';
include __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="mb-1">Add-ons</h1>
<p class="text-muted small">Extras guests can add to a booking (activities, extra mattress...). Prices are copied into each booking, so changes never affect old bookings. Set <strong>Available per day</strong> to how many the farm has; once a day is fully booked, guests and walk-ins can't add more for that day.</p>
<div class="row g-4">
  <div class="col-lg-7">
    <div class="card"><div class="table-responsive">
      <table class="table table-jm mb-0">
        <thead><tr><th>Name</th><th class="text-end">Price</th><th>Unit</th><th class="text-end">Per day</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($addons as $ad): ?>
            <tr class="<?= $ad['is_active'] ? '' : 'text-muted' ?>">
              <td><?= e($ad['name']) ?><br><span class="small text-muted"><?= e($ad['description']) ?></span></td>
              <td class="text-end"><?= money($ad['price']) ?></td>
              <td class="small"><?= e($ad['unit_label']) ?></td>
              <td class="text-end"><?= $ad['daily_stock'] === null ? '<span class="small text-muted">Unlimited</span>' : (int) $ad['daily_stock'] ?></td>
              <td><?= $ad['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Hidden</span>' ?></td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-primary" href="?edit=<?= (int) $ad['id'] ?>">Edit</a>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this add-on?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $ad['id'] ?>"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-lg-5">
    <form method="post" class="card p-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
      <h5><?= $edit ? 'Edit ' . e($edit['name']) : 'New add-on' ?></h5>
      <div class="mb-2"><label class="form-label small">Name</label><input name="name" class="form-control" value="<?= e($edit['name'] ?? '') ?>" required></div>
      <div class="mb-2"><label class="form-label small">Description</label><input name="description" class="form-control" value="<?= e($edit['description'] ?? '') ?>"></div>
      <div class="row g-2 mb-2">
        <div class="col"><label class="form-label small">Price (₱)</label><input type="number" step="0.01" min="0" name="price" class="form-control" value="<?= e($edit['price'] ?? '') ?>" required></div>
        <div class="col"><label class="form-label small">Unit</label><input name="unit_label" class="form-control" value="<?= e($edit['unit_label'] ?? 'each') ?>" placeholder="per hour"></div>
      </div>
      <div class="mb-2">
        <label class="form-label small" for="dailyStock">Available per day</label>
        <input type="number" min="0" step="1" name="daily_stock" id="dailyStock" class="form-control" value="<?= e($edit['daily_stock'] ?? '') ?>" placeholder="Unlimited" aria-describedby="dailyStockHelp">
        <div class="form-text" id="dailyStockHelp">How many the farm has each day, e.g. 1 billiard table. Leave blank for no limit.</div>
      </div>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" name="is_active" id="adAct" value="1" <?= ($edit['is_active'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="adAct">Show to guests</label></div>
      <div><button class="btn btn-primary">Save</button> <?php if ($edit): ?><a href="addons.php" class="btn btn-link">Cancel</a><?php endif; ?></div>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php';

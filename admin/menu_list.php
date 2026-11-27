<?php
// Farm menu manager (originally menu_list.php). Now login-protected, inside the admin layout.
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

// Delete (moved from menu_delete.php, now POST + CSRF instead of a GET link)
if (is_post() && post('action') === 'delete') {
    csrf_check();
    $offering_id = (int) post('offering_id');
    $price_variant_id = (int) post('price_variant_id');
    if ($price_variant_id) {
        // Row had a specific price variant: remove just that variant
        db_exec('DELETE FROM price_variants WHERE id = ?', [$price_variant_id]);
        audit('menu.delete_variant', 'menu', $price_variant_id);
    } elseif ($offering_id) {
        // Row had no price variant (e.g. an Activity): remove the whole offering
        db_exec('DELETE FROM offerings WHERE id = ?', [$offering_id]);
        audit('menu.delete_item', 'menu', $offering_id);
    }
    flash('success', 'Menu row deleted.');
    redirect('admin/menu_list.php?type_id=' . (int) post('type_id'));
}

$types = db_all('SELECT id, name FROM offering_types ORDER BY id');
$selected_type_id = (int) get('type_id') ?: (int) ($types[0]['id'] ?? 0);

$rows = db_all('SELECT c.name AS category, o.id AS offering_id, o.name AS item, o.description AS item_description,
                       pv.id AS price_variant_id, pv.variant_label, pv.price, pv.notes
                FROM offerings o
                JOIN categories c ON o.category_id = c.id
                JOIN offering_types ot ON c.offering_type_id = ot.id
                LEFT JOIN price_variants pv ON pv.offering_id = o.id
                WHERE ot.id = ?
                ORDER BY c.id, o.name, pv.variant_label', [$selected_type_id]);

$pageTitle = 'Farm menu';
$activeAdmin = 'menu';
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div><h1>Farm menu</h1><p class="text-muted small mb-0">Food prices here are used for food pre-orders and shown on the public menu page.</p></div>
  <div class="d-flex gap-2 align-items-center">
    <form method="get" class="d-flex align-items-center gap-2">
      <label for="type_id" class="small text-nowrap">Viewing:</label>
      <select name="type_id" id="type_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($types as $t): ?>
          <option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $selected_type_id ? 'selected' : '' ?>><?= e($t['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a class="btn btn-primary text-nowrap" href="menu_edit.php?type_id=<?= $selected_type_id ?>"><i class="bi bi-plus-lg"></i> Add item</a>
  </div>
</div>
<?php if (stripos($types[array_search($selected_type_id, array_column($types, 'id'))]['name'] ?? '', 'room') !== false): ?>
  <div class="alert alert-info small">This is the price list shown in the menu. Bookable units and their rates are managed in <a href="accommodations.php">Accommodations</a>; entrance and swimming fees used for bookings are in <a href="settings.php">Settings</a>.</div>
<?php endif; ?>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-jm mb-0">
      <thead><tr><th>Category</th><th>Item</th><th>Description</th><th>Variant</th><th class="text-end">Price</th><th>Notes</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr>
            <td class="small text-muted"><?= e($row['category']) ?></td>
            <td><?= e($row['item']) ?></td>
            <td class="small"><?= e($row['item_description']) ?></td>
            <td class="small"><?= e($row['variant_label']) ?></td>
            <td class="text-end"><?= $row['price'] !== null ? money($row['price']) : '' ?></td>
            <td class="small"><?= e($row['notes']) ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="menu_edit.php?offering_id=<?= (int) $row['offering_id'] ?>&price_variant_id=<?= $row['price_variant_id'] !== null ? (int) $row['price_variant_id'] : '' ?>&type_id=<?= $selected_type_id ?>">Edit</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this row?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="delete">
                <input type="hidden" name="offering_id" value="<?= (int) $row['offering_id'] ?>">
                <input type="hidden" name="price_variant_id" value="<?= (int) ($row['price_variant_id'] ?? 0) ?>">
                <input type="hidden" name="type_id" value="<?= $selected_type_id ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">No items yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php';

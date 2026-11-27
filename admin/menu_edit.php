<?php
// Add / edit a menu item (originally menu_edit.php). Same logic, now with login + CSRF.
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$offering_id = (int) get('offering_id') ?: null;
$price_variant_id = (int) get('price_variant_id') ?: null;
$type_id = (int) get('type_id') ?: null;
$back_url = 'admin/menu_list.php' . ($type_id ? '?type_id=' . $type_id : '');

$data = ['category_id' => '', 'name' => '', 'description' => '', 'variant_label' => 'default', 'price' => '', 'notes' => ''];

// Load existing values when editing
if ($offering_id) {
    if ($row = db_one('SELECT category_id, name, description FROM offerings WHERE id = ?', [$offering_id])) {
        $data = array_merge($data, $row);
    }
    if ($price_variant_id && ($row = db_one('SELECT variant_label, price, notes FROM price_variants WHERE id = ?', [$price_variant_id]))) {
        $data = array_merge($data, $row);
    }
}

// Handle save
if (is_post()) {
    csrf_check();
    $category_id = (int) post('category_id');
    $name = post('name');
    $description = post('description') !== '' ? post('description') : null;
    $variant_label = post('variant_label') !== '' ? post('variant_label') : 'default';
    $price = post('price') !== '' ? (float) post('price') : null;
    $notes = post('notes') !== '' ? post('notes') : null;

    if (!$category_id || $name === '') {
        flash('error', 'Choose a category and enter a name.');
        redirect('admin/menu_edit.php?' . http_build_query($_GET));
    }

    if ($offering_id) {
        db_exec('UPDATE offerings SET category_id = ?, name = ?, description = ? WHERE id = ?', [$category_id, $name, $description, $offering_id]);
        if ($price_variant_id) {
            if ($price !== null) {
                db_exec('UPDATE price_variants SET variant_label = ?, price = ?, notes = ? WHERE id = ?', [$variant_label, $price, $notes, $price_variant_id]);
            } else {
                // Price cleared: remove this variant row
                db_exec('DELETE FROM price_variants WHERE id = ?', [$price_variant_id]);
            }
        } elseif ($price !== null) {
            // No variant existed before, but a price was entered now
            db_exec('INSERT INTO price_variants (offering_id, variant_label, price, notes) VALUES (?, ?, ?, ?)', [$offering_id, $variant_label, $price, $notes]);
        }
        audit('menu.update', 'menu', $offering_id, $name . ($price !== null ? ' ' . money($price) : ''));
    } else {
        db_exec('INSERT INTO offerings (category_id, name, description) VALUES (?, ?, ?)', [$category_id, $name, $description]);
        $new_offering_id = db_insert_id();
        if ($price !== null) {
            db_exec('INSERT INTO price_variants (offering_id, variant_label, price, notes) VALUES (?, ?, ?, ?)', [$new_offering_id, $variant_label, $price, $notes]);
        }
        audit('menu.create', 'menu', $new_offering_id, $name);
    }
    flash('success', $name . ' saved.');
    redirect($back_url);
}

// Categories for the dropdown
$categories = db_all('SELECT c.id, c.name AS category_name, ot.name AS type_name
                      FROM categories c JOIN offering_types ot ON c.offering_type_id = ot.id ORDER BY ot.id, c.name');

$pageTitle = $offering_id ? 'Edit item' : 'Add new item';
$activeAdmin = 'menu';
include __DIR__ . '/../includes/admin_header.php';
?>
<a href="<?= e(url($back_url)) ?>" class="small"><i class="bi bi-arrow-left"></i> Farm menu</a>
<h1 class="mt-1 mb-3"><?= e($pageTitle) ?></h1>
<form method="post" class="card p-4" style="max-width:620px">
  <?= csrf_field() ?>
  <div class="mb-3"><label class="form-label">Category</label>
    <select name="category_id" class="form-select" required>
      <option value="">-- Select category --</option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= (int) $c['id'] ?>" <?= (string) $c['id'] === (string) $data['category_id'] ? 'selected' : '' ?>><?= e($c['type_name'] . ' - ' . $c['category_name']) ?></option>
      <?php endforeach; ?>
    </select></div>
  <div class="mb-3"><label class="form-label">Item name</label><input type="text" name="name" class="form-control" value="<?= e($data['name']) ?>" required></div>
  <div class="mb-3"><label class="form-label">Description <small class="text-muted">(optional)</small></label><input type="text" name="description" class="form-control" value="<?= e($data['description']) ?>"></div>
  <div class="row g-3 mb-3">
    <div class="col-md-6"><label class="form-label">Variant label</label><input type="text" name="variant_label" class="form-control" placeholder="e.g. Medium, Adult, or default" value="<?= e($data['variant_label']) ?>"></div>
    <div class="col-md-6"><label class="form-label">Price</label><input type="number" step="0.01" name="price" class="form-control" placeholder="Leave blank if none listed" value="<?= e((string) ($data['price'] ?? '')) ?>"></div>
  </div>
  <div class="mb-3"><label class="form-label">Notes <small class="text-muted">(optional)</small></label><input type="text" name="notes" class="form-control" value="<?= e($data['notes']) ?>"></div>
  <div><button class="btn btn-primary">Save</button> <a href="<?= e(url($back_url)) ?>" class="btn btn-link">Cancel</a></div>
</form>
<?php include __DIR__ . '/../includes/admin_footer.php';

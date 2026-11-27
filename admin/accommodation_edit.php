<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$id = (int) get('id');
$a = $id ? db_one('SELECT * FROM accommodations WHERE id = ?', [$id]) : null;
if ($id && !$a) redirect('admin/accommodations.php');
$a = $a ?: ['name' => '', 'type' => 'day_cottage', 'description' => '', 'inclusions' => '', 'capacity' => 6, 'base_rate' => '', 'rate_type' => 'per_day', 'photo_path' => null, 'is_active' => 1, 'sort_order' => 0];

if (is_post()) {
    csrf_check();
    $data = [
        'name'        => post('name'),
        'type'        => post('type') === 'overnight_room' ? 'overnight_room' : 'day_cottage',
        'description' => post('description') ?: null,
        'inclusions'  => post('inclusions') ?: null,
        'capacity'    => (int) post('capacity'),
        'base_rate'   => round((float) post('base_rate'), 2),
        'rate_type'   => in_array(post('rate_type'), ['per_day', 'per_night', 'per_head'], true) ? post('rate_type') : 'per_day',
        // An archived unit stays closed until it is restored from the Archived tab
        'is_active'   => post('is_active') && empty($a['archived_at']) ? 1 : 0,
        'sort_order'  => (int) post('sort_order'),
    ];
    $errors = [];
    if (mb_strlen($data['name']) < 2) $errors[] = 'Enter a name.';
    if ($data['capacity'] < 1) $errors[] = 'Capacity must be at least 1.';
    if ($data['base_rate'] <= 0) $errors[] = 'Enter a rate greater than zero.';
    if ($data['type'] === 'overnight_room' && $data['rate_type'] === 'per_day') $data['rate_type'] = 'per_night';
    if ($data['type'] === 'day_cottage' && $data['rate_type'] === 'per_night') $data['rate_type'] = 'per_day';

    // Optional photo
    $photo = $a['photo_path'];
    if (!empty($_FILES['photo']['name'])) {
        $f = $_FILES['photo'];
        $mime = $f['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if (!$ext || $f['size'] > MAX_UPLOAD_BYTES) {
            $errors[] = 'Photo must be a JPG, PNG or WEBP image up to ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.';
        } else {
            $photo = 'unit_' . bin2hex(random_bytes(8)) . '.' . $ext;
            move_uploaded_file($f['tmp_name'], UPLOADS_DIR . '/accommodations/' . $photo);
        }
    }
    if (post('remove_photo')) $photo = null;

    if ($errors) {
        remember_input();
        foreach ($errors as $err) flash('error', $err);
        redirect('admin/accommodation_edit.php' . ($id ? '?id=' . $id : ''));
    }

    if ($id) {
        db_exec('UPDATE accommodations SET name=?, type=?, description=?, inclusions=?, capacity=?, base_rate=?, rate_type=?, is_active=?, sort_order=?, photo_path=? WHERE id=?',
            [$data['name'], $data['type'], $data['description'], $data['inclusions'], $data['capacity'], $data['base_rate'], $data['rate_type'], $data['is_active'], $data['sort_order'], $photo, $id]);
        $changes = [];
        foreach (['name', 'capacity', 'base_rate', 'is_active'] as $k) if ((string) $a[$k] !== (string) $data[$k]) $changes[] = "$k: {$a[$k]} -> {$data[$k]}";
        audit('accommodation.update', 'accommodation', $id, $data['name'] . ($changes ? ' (' . implode(', ', $changes) . ')' : ''));
    } else {
        db_exec('INSERT INTO accommodations (name, type, description, inclusions, capacity, base_rate, rate_type, is_active, sort_order, photo_path) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$data['name'], $data['type'], $data['description'], $data['inclusions'], $data['capacity'], $data['base_rate'], $data['rate_type'], $data['is_active'], $data['sort_order'], $photo]);
        audit('accommodation.create', 'accommodation', db_insert_id(), $data['name']);
    }
    flash('success', $data['name'] . ' saved.');
    redirect('admin/accommodations.php');
}

$v = fn($k) => old($k, $a[$k] ?? '');
$pageTitle = $id ? 'Edit ' . $a['name'] : 'Add unit';
$activeAdmin = 'accommodations';
include __DIR__ . '/../includes/admin_header.php';
?>
<a href="accommodations.php" class="small"><i class="bi bi-arrow-left"></i> Accommodations</a>
<h1 class="mt-1 mb-3"><?= e($pageTitle) ?></h1>
<form method="post" enctype="multipart/form-data" class="card p-4" style="max-width:820px">
  <?= csrf_field() ?>
  <div class="row g-3">
    <div class="col-md-8"><label class="form-label">Name</label><input name="name" class="form-control" value="<?= e($v('name')) ?>" required placeholder="e.g. Open Cottage 3"></div>
    <div class="col-md-4"><label class="form-label">Type</label>
      <select name="type" class="form-select"><option value="day_cottage" <?= $v('type') === 'day_cottage' ? 'selected' : '' ?>>Day cottage</option><option value="overnight_room" <?= $v('type') === 'overnight_room' ? 'selected' : '' ?>>Overnight room</option></select></div>
    <div class="col-md-4"><label class="form-label">Capacity (guests)</label><input type="number" name="capacity" min="1" class="form-control" value="<?= e($v('capacity')) ?>" required></div>
    <div class="col-md-4"><label class="form-label">Rate (₱)</label><input type="number" step="0.01" min="1" name="base_rate" class="form-control" value="<?= e($v('base_rate')) ?>" required></div>
    <div class="col-md-4"><label class="form-label">Rate is</label>
      <select name="rate_type" class="form-select">
        <?php foreach (['per_day' => 'per day (cottages)', 'per_night' => 'per night (rooms)', 'per_head' => 'per guest per day'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $v('rate_type') === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2"><?= e($v('description')) ?></textarea></div>
    <div class="col-md-8"><label class="form-label">Inclusions</label><input name="inclusions" class="form-control" value="<?= e($v('inclusions')) ?>" placeholder="e.g. Entrance, swimming and breakfast"></div>
    <div class="col-md-4"><label class="form-label">Display order</label><input type="number" name="sort_order" class="form-control" value="<?= e($v('sort_order')) ?>"></div>
    <div class="col-md-8">
      <label class="form-label">Photo</label>
      <input type="file" name="photo" class="form-control" accept="image/jpeg,image/png,image/webp">
      <?php if ($a['photo_path']): ?>
        <div class="form-check mt-1"><input class="form-check-input" type="checkbox" name="remove_photo" id="rp" value="1"><label for="rp" class="form-check-label small">Remove current photo</label></div>
      <?php endif; ?>
    </div>
    <div class="col-md-4"><img src="<?= e(accommodation_photo($a['photo_path'], $a['type'])) ?>" class="w-100 rounded" style="max-height:110px;object-fit:cover" alt=""></div>
    <?php if (!empty($a['archived_at'])): ?>
      <div class="col-12"><div class="alert alert-info small mb-0">This unit was archived on <?= e(fmt_date($a['archived_at'], 'M j, Y')) ?>. You can still edit its details; to take bookings again, restore it from <a href="accommodations.php?view=archived">Accommodations &rarr; Archived</a>.</div></div>
    <?php else: ?>
      <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="act" value="1" <?= $v('is_active') ? 'checked' : '' ?>><label for="act" class="form-check-label">Open for booking</label></div></div>
    <?php endif; ?>
  </div>
  <div class="mt-4"><button class="btn btn-primary">Save</button> <a href="accommodations.php" class="btn btn-link">Cancel</a></div>
</form>
<?php include __DIR__ . '/../includes/admin_footer.php';

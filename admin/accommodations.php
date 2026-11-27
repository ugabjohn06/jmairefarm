<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

// Two lifecycles: "hidden" is a temporary closure (repairs, off-season);
// "archived" retires a unit for good while keeping it, and its bookings, on record.
if (is_post()) {
    csrf_check();
    $id = (int) post('id');
    $a = db_one('SELECT * FROM accommodations WHERE id = ?', [$id]);
    $action = post('action');
    $back = 'admin/accommodations.php' . ($a && $a['archived_at'] ? '?view=archived' : '');
    if ($a && $action === 'toggle' && !$a['archived_at']) {
        db_exec('UPDATE accommodations SET is_active = 1 - is_active WHERE id = ?', [$id]);
        audit($a['is_active'] ? 'accommodation.deactivate' : 'accommodation.activate', 'accommodation', $id, $a['name']);
        flash('success', $a['name'] . ($a['is_active'] ? ' is hidden from booking.' : ' is open for booking.'));
    } elseif ($a && $action === 'archive' && !$a['archived_at']) {
        db_exec('UPDATE accommodations SET archived_at = NOW(), is_active = 0 WHERE id = ?', [$id]);
        audit('accommodation.archive', 'accommodation', $id, $a['name']);
        flash('success', $a['name'] . ' archived. Its bookings are kept; find it under Archived to restore it.');
    } elseif ($a && $action === 'restore' && $a['archived_at']) {
        db_exec('UPDATE accommodations SET archived_at = NULL WHERE id = ?', [$id]);
        audit('accommodation.restore', 'accommodation', $id, $a['name']);
        flash('success', $a['name'] . ' restored as hidden. Open it for booking when it is ready.');
        $back = 'admin/accommodations.php';
    } elseif ($a && $action === 'delete') {
        if (db_value('SELECT COUNT(*) FROM bookings WHERE accommodation_id = ?', [$id])) {
            flash('error', $a['name'] . ' has bookings, so it can only be archived (to keep the records).');
        } else {
            db_exec('DELETE FROM accommodations WHERE id = ?', [$id]);
            audit('accommodation.delete', 'accommodation', $id, $a['name']);
            flash('success', $a['name'] . ' deleted.');
        }
    }
    redirect($back);
}

$archivedView = get('view') === 'archived';
$units = db_all("SELECT a.*,
                   (SELECT COUNT(*) FROM bookings b WHERE b.accommodation_id = a.id AND b.status IN ('pending','confirmed','checked_in') AND b.check_out > CURDATE()) AS upcoming,
                   (SELECT COUNT(*) FROM bookings b WHERE b.accommodation_id = a.id) AS booking_count
                 FROM accommodations a
                 WHERE a.archived_at IS " . ($archivedView ? 'NOT NULL' : 'NULL') . "
                 ORDER BY a.type, a.sort_order, a.name");
$archivedCount = (int) db_value('SELECT COUNT(*) FROM accommodations WHERE archived_at IS NOT NULL');
$currentCount = (int) db_value('SELECT COUNT(*) FROM accommodations WHERE archived_at IS NULL');
$pageTitle = 'Accommodations';
$activeAdmin = 'accommodations';
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div><h1>Accommodations</h1><p class="text-muted small mb-0">One row per physical cottage or room. Rate changes only affect new bookings.</p></div>
  <a class="btn btn-primary" href="accommodation_edit.php"><i class="bi bi-plus-lg"></i> Add unit</a>
</div>
<ul class="nav nav-tabs mb-0">
  <li class="nav-item"><a class="nav-link<?= $archivedView ? '' : ' active' ?>" href="accommodations.php"<?= $archivedView ? '' : ' aria-current="page"' ?>>Current <span class="text-muted">(<?= $currentCount ?>)</span></a></li>
  <li class="nav-item"><a class="nav-link<?= $archivedView ? ' active' : '' ?>" href="accommodations.php?view=archived"<?= $archivedView ? ' aria-current="page"' : '' ?>>Archived <span class="text-muted">(<?= $archivedCount ?>)</span></a></li>
</ul>
<div class="card" style="border-top-left-radius:0">
  <?php if (!$units): ?>
    <p class="text-muted small p-4 mb-0"><?= $archivedView ? 'No archived units. Archive a unit you no longer rent out; its past bookings stay on record.' : 'No units yet. Use Add unit to create one.' ?></p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover table-jm mb-0">
      <thead><tr><th></th><th>Name</th><th>Type</th><th class="text-center">Capacity</th><th class="text-end">Rate</th><th class="text-center">Upcoming</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($units as $a): $name = e(addslashes($a['name'])); ?>
          <tr class="<?= $a['is_active'] ? '' : 'text-muted' ?>">
            <td><img src="<?= e(accommodation_photo($a['photo_path'], $a['type'])) ?>" width="64" height="44" style="object-fit:cover;border-radius:var(--jm-radius)" alt=""></td>
            <td class="fw-semibold"><?= e($a['name']) ?></td>
            <td><?= e(type_label($a['type'])) ?></td>
            <td class="text-center"><?= (int) $a['capacity'] ?></td>
            <td class="text-end"><?= money($a['base_rate']) ?> <span class="small text-muted">/<?= e(str_replace('per_', '', $a['rate_type'])) ?></span></td>
            <td class="text-center"><?= (int) $a['upcoming'] ?></td>
            <td><?php if ($a['archived_at']): ?><span class="badge text-bg-secondary">Archived <?= e(fmt_date($a['archived_at'], 'M j, Y')) ?></span>
                <?php else: ?><?= $a['is_active'] ? '<span class="badge text-bg-success">Open</span>' : '<span class="badge text-bg-secondary">Hidden</span>' ?><?php endif; ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-primary" href="accommodation_edit.php?id=<?= (int) $a['id'] ?>">Edit</a>
              <?php if ($a['archived_at']): ?>
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="restore">
                  <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Restore</button></form>
              <?php else: ?>
                <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="toggle">
                  <button class="btn btn-sm btn-outline-secondary"><?= $a['is_active'] ? 'Hide' : 'Open' ?></button></form>
                <form method="post" class="d-inline" onsubmit="return confirm('<?= $a['upcoming'] ? "Archive $name? It still has " . (int) $a['upcoming'] . " upcoming booking(s). Those stay valid, but no new bookings can be made for it." : "Archive $name? It stops taking bookings and moves to Archived. Past bookings are kept." ?>')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="archive">
                  <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-archive"></i> Archive</button></form>
              <?php endif; ?>
              <?php if (!$a['booking_count']): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete <?= $name ?> permanently? It has never been booked.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="delete">
                  <button class="btn btn-sm btn-outline-danger" aria-label="Delete <?= e($a['name']) ?>"><i class="bi bi-trash"></i></button></form>
              <?php else: $why = 'Can’t delete: ' . (int) $a['booking_count'] . ' booking' . ($a['booking_count'] == 1 ? '' : 's') . ' on record. Archive it instead.'; ?>
                <span class="d-inline-block" tabindex="0" title="<?= e($why) ?>" aria-label="<?= e($a['name'] . ': ' . $why) ?>">
                  <button class="btn btn-sm btn-outline-secondary" disabled aria-hidden="true" style="pointer-events:none"><i class="bi bi-trash"></i></button></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<p class="small text-muted mt-2"><strong>Hide</strong> closes a unit for a while (repairs, off-season). <strong>Archive</strong> retires it: it leaves this list and stops taking bookings, but its past bookings stay on record. Only units that were never booked can be deleted; a greyed-out <i class="bi bi-trash"></i> means the unit has bookings on record.</p>
<?php include __DIR__ . '/../includes/admin_footer.php';

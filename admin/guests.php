<?php
require __DIR__ . '/../includes/bootstrap.php';
require_staff();

$q = get('q');
$params = [];
$where = '';
if ($q !== '') {
    $where = 'WHERE b.guest_name LIKE ? OR b.guest_phone LIKE ? OR b.guest_email LIKE ?';
    $params = ["%$q%", "%$q%", "%$q%"];
}
// One row per guest: registered guests by account, walk-ins by phone number
$guests = db_all("SELECT
                    COALESCE(CONCAT('u', b.user_id), CONCAT('p', b.guest_phone)) AS gkey,
                    MAX(b.user_id) AS user_id,
                    MAX(b.guest_name) AS guest_name, MAX(b.guest_phone) AS guest_phone, MAX(b.guest_email) AS guest_email,
                    COUNT(*) AS bookings,
                    SUM(b.status = 'completed') AS completed,
                    MAX(b.check_in) AS last_visit,
                    COALESCE(SUM((SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.booking_id = b.id AND p.status = 'verified')),0) AS total_paid
                  FROM bookings b $where
                  GROUP BY gkey
                  ORDER BY last_visit DESC
                  LIMIT 300", $params);

$pageTitle = 'Guests';
$activeAdmin = 'guests';
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1>Guests</h1>
  <form class="d-flex gap-2" method="get"><input name="q" class="form-control" placeholder="Name, phone or email" value="<?= e($q) ?>"><button class="btn btn-primary">Search</button></form>
</div>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-jm mb-0">
      <thead><tr><th>Guest</th><th>Contact</th><th class="text-center">Bookings</th><th class="text-center">Completed</th><th>Last visit</th><?php if (is_admin()): ?><th class="text-end">Total paid</th><?php endif; ?><th></th></tr></thead>
      <tbody>
        <?php foreach ($guests as $g): ?>
          <tr>
            <td><?= e($g['guest_name']) ?> <?= $g['user_id'] ? '<span class="badge text-bg-light">account</span>' : '' ?></td>
            <td class="small"><?= e($g['guest_phone']) ?><br><span class="text-muted"><?= e($g['guest_email']) ?></span></td>
            <td class="text-center"><?= (int) $g['bookings'] ?></td>
            <td class="text-center"><?= (int) $g['completed'] ?></td>
            <td><?= e(fmt_date($g['last_visit'])) ?></td>
            <?php if (is_admin()): ?><td class="text-end"><?= money($g['total_paid']) ?></td><?php endif; ?>
            <td class="text-end">
              <?php if ($g['user_id']): ?><a class="btn btn-sm btn-outline-primary" href="guest.php?id=<?= (int) $g['user_id'] ?>">History</a>
              <?php else: ?><a class="btn btn-sm btn-outline-primary" href="bookings.php?q=<?= urlencode($g['guest_phone']) ?>">Bookings</a><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$guests): ?><tr><td colspan="7" class="text-center text-muted py-4">No guests found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php';

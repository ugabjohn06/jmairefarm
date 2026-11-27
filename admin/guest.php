<?php
require __DIR__ . '/../includes/bootstrap.php';
require_staff();

$g = db_one('SELECT * FROM users WHERE id = ?', [(int) get('id')]);
if (!$g) {
    flash('error', 'Guest not found.');
    redirect('admin/guests.php');
}
$bookings = db_all('SELECT b.*, a.name AS accommodation_name, a.type,
                      (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.booking_id = b.id AND p.status = \'verified\') AS amount_paid
                    FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
                    WHERE b.user_id = ? ORDER BY b.check_in DESC', [(int) $g['id']]);
$spent = array_sum(array_column($bookings, 'amount_paid'));

$pageTitle = $g['name'];
$activeAdmin = 'guests';
include __DIR__ . '/../includes/admin_header.php';
?>
<a href="guests.php" class="small"><i class="bi bi-arrow-left"></i> Guests</a>
<div class="row g-4 mt-1">
  <div class="col-lg-4">
    <div class="card p-3">
      <h4 class="mb-1"><?= e($g['name']) ?></h4>
      <p class="small text-muted mb-2">Guest since <?= e(fmt_date($g['created_at'])) ?></p>
      <p class="mb-1"><i class="bi bi-envelope"></i> <?= e($g['email']) ?></p>
      <p class="mb-3"><i class="bi bi-telephone"></i> <?= e($g['phone']) ?></p>
      <div class="row text-center">
        <div class="col"><div class="fs-4 fw-semibold text-primary"><?= count($bookings) ?></div><div class="small text-muted">bookings</div></div>
        <?php if (is_admin()): ?><div class="col"><div class="fs-5 fw-semibold text-primary"><?= money($spent) ?></div><div class="small text-muted">total paid</div></div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="card">
      <table class="table table-hover table-jm mb-0">
        <thead><tr><th>Code</th><th>Unit</th><th>Date</th><th class="text-end">Total</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($bookings as $b): ?>
            <tr><td><a href="booking.php?id=<?= (int) $b['id'] ?>"><?= e($b['booking_code']) ?></a></td><td><?= e($b['accommodation_name']) ?></td>
              <td class="small"><?= e(stay_label($b)) ?></td><td class="text-end"><?= money($b['total_amount']) ?></td><td><?= status_badge($b['status']) ?></td></tr>
          <?php endforeach; ?>
          <?php if (!$bookings): ?><tr><td colspan="5" class="text-center text-muted py-3">No bookings yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php';

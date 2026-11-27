<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (is_staff()) redirect('admin/bookings.php');

$bookings = db_all('SELECT b.*, a.name AS accommodation_name, a.type,
                      (SELECT COUNT(*) FROM payments p WHERE p.booking_id = b.id AND p.status = \'pending\') AS pending_payments
                    FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
                    WHERE b.user_id = ? ORDER BY b.check_in >= CURDATE() DESC, b.check_in ASC', [(int) $user['id']]);
$upcoming = array_filter($bookings, fn($b) => in_array($b['status'], BLOCKING_STATUSES, true));
$past = array_filter($bookings, fn($b) => !in_array($b['status'], BLOCKING_STATUSES, true));

$pageTitle = 'My bookings';
$activeNav = 'mine';
include __DIR__ . '/includes/header.php';

$renderCard = function (array $b) { ?>
  <div class="col-md-6">
    <div class="card p-3 p-md-4 h-100 d-flex flex-column">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <div class="small text-muted tnum"><?= e($b['booking_code']) ?></div>
          <h5 class="mb-0 display-font" style="font-size:1.25rem"><?= e($b['accommodation_name']) ?></h5>
        </div>
        <?= status_badge($b['status']) ?>
      </div>
      <p class="small mb-1"><i class="bi bi-calendar3"></i> <?= e(stay_label($b)) ?></p>
      <p class="small mb-2"><i class="bi bi-people"></i> <?= (int) $b['num_adults'] + (int) $b['num_kids'] ?> guests &middot; <?= money($b['total_amount']) ?></p>
      <?php if ($b['status'] === 'pending' && !$b['pending_payments']): ?>
        <p class="small text-warning-emphasis mb-2"><i class="bi bi-exclamation-circle"></i> Waiting for your downpayment (until <?= e(fmt_datetime($b['expires_at'])) ?>)</p>
      <?php elseif ($b['status'] === 'pending'): ?>
        <p class="small text-info-emphasis mb-2"><i class="bi bi-hourglass-split"></i> Receipt is being checked</p>
      <?php endif; ?>
      <a href="<?= e(url('booking.php?code=' . urlencode($b['booking_code']))) ?>" class="btn btn-sm btn-outline-primary mt-auto align-self-start">
        <?= $b['status'] === 'pending' && !$b['pending_payments'] ? 'Pay / upload receipt' : 'View details' ?>
      </a>
    </div>
  </div>
<?php };
?>
<section class="page-head">
  <div class="container d-flex justify-content-between align-items-end flex-wrap gap-3">
    <div><h1>My bookings</h1><p>Pay, upload receipts and print receipts for your visits.</p></div>
    <a class="btn btn-sun" href="<?= e(url('book.php')) ?>"><i class="bi bi-plus-lg me-1"></i> New booking</a>
  </div>
</section>
<div class="container pb-5">
  <h2 class="section-title mt-0">Upcoming</h2>
  <div class="row g-3">
    <?php foreach ($upcoming as $b) $renderCard($b); ?>
    <?php if (!$upcoming): ?><p class="text-muted">No upcoming bookings. <a href="<?= e(url('accommodations.php')) ?>">Find a cottage or room</a>.</p><?php endif; ?>
  </div>
  <?php if ($past): ?>
    <h2 class="section-title">History</h2>
    <div class="row g-3"><?php foreach ($past as $b) $renderCard($b); ?></div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php';

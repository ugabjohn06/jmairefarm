<?php
// Lets guests without an account (e.g. walk-ins) open their booking with code + phone.
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    csrf_check();
    // Slow down guessing: max 10 tries per session per 10 minutes
    $_SESSION['lookup_tries'] = array_filter($_SESSION['lookup_tries'] ?? [], fn($t) => $t > time() - 600);
    if (count($_SESSION['lookup_tries']) >= 10) {
        flash('error', 'Too many attempts. Please wait a few minutes and try again.');
        redirect('find_booking.php');
    }
    $_SESSION['lookup_tries'][] = time();

    $b = get_booking_by_code(post('code'));
    $digits = fn($s) => preg_replace('/\D/', '', (string) $s);
    if ($b && $digits(post('phone')) !== '' && substr($digits($b['guest_phone']), -7) === substr($digits(post('phone')), -7)) {
        $_SESSION['unlocked_bookings'][] = (int) $b['id'];
        redirect('booking.php?code=' . urlencode($b['booking_code']));
    }
    flash('error', 'We could not find a booking with that code and mobile number.');
    redirect('find_booking.php');
}

$pageTitle = 'Find my booking';
$activeNav = 'find';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
  <div class="card p-4 p-md-5 narrow-card">
    <h1 class="h2 mb-2">Find my booking</h1>
    <p class="text-muted small">Enter the booking code we gave you (like <strong>JM-<?= date('Y') ?>-0042</strong>) and the mobile number you used.</p>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label">Booking code</label><input name="code" class="form-control text-uppercase" required placeholder="JM-<?= date('Y') ?>-0000"></div>
      <div class="mb-3"><label class="form-label">Mobile number</label><input name="phone" type="tel" class="form-control" required></div>
      <button class="btn btn-primary w-100">Find booking</button>
    </form>
    <?php if (!is_logged_in()): ?>
      <p class="small text-center mt-3 mb-0">Have an account? <a href="<?= e(url('login.php')) ?>">Log in</a> to see all your bookings.</p>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php';

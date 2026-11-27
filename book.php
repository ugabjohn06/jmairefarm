<?php
require __DIR__ . '/includes/bootstrap.php';

// Staff use the walk-in form instead
if (is_staff()) {
    redirect('admin/walkin.php?' . http_build_query($_GET));
}
$user = require_login();

if (is_post()) {
    csrf_check();
    if (!post('agree')) {
        remember_input();
        flash('error', 'Please agree to the booking policy.');
        redirect('book.php');
    }
    $result = create_booking($_POST, ['source' => 'online', 'user_id' => (int) $user['id'], 'created_by' => (int) $user['id']]);
    if (!$result['ok']) {
        remember_input();
        foreach ($result['errors'] as $err) flash('error', $err);
        redirect('book.php');
    }
    clear_old();
    notify_booking($result['id'], 'created');
    flash('success', 'Booking ' . $result['code'] . ' created! Your date is on hold. Please pay the downpayment below.');
    redirect('booking.php?code=' . urlencode($result['code']));
}

$pageTitle = 'Book your visit';
$formMode = 'guest';
$guests = max(0, (int) get('guests'));
$prefill = [
    'accommodation_id' => get('accommodation_id'),
    'check_in'         => get('check_in'),
    'check_out'        => get('check_out'),
    'num_adults'       => $guests ?: 2,
    'guest_name'       => $user['name'],
    'guest_phone'      => $user['phone'],
    'guest_email'      => $user['email'],
];

include __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div class="container">
    <h1>Book your visit</h1>
    <p>Pick a cottage or room, choose your date, and see the full price before you confirm.</p>
  </div>
</section>
<div class="container pb-5">
  <?php include __DIR__ . '/includes/booking_form.php'; ?>
</div>
<?php
$extraScripts = '<script src="' . e(url('assets/js/booking.js')) . '?v=' . filemtime(__DIR__ . '/assets/js/booking.js') . '"></script>';
include __DIR__ . '/includes/footer.php';

<?php
// Walk-in / phone booking by staff. Uses the same booking service as the guest form,
// so the overlap check and price calculation are identical.
require __DIR__ . '/../includes/bootstrap.php';
$me = require_staff();

if (is_post()) {
    csrf_check();
    $payAmount = round((float) post('pay_amount'), 2);
    $method = in_array(post('pay_method'), ['cash', 'gcash', 'bank'], true) ? post('pay_method') : 'cash';

    // Link to an existing guest account when the email matches
    $email = strtolower(post('guest_email'));
    $userId = $email ? db_value("SELECT id FROM users WHERE email = ? AND role = 'guest'", [$email]) : null;

    $result = create_booking($_POST, [
        'source'     => 'walk_in',
        'status'     => 'pending', // becomes confirmed below once the payment is recorded
        'user_id'    => $userId ? (int) $userId : null,
        'created_by' => (int) $me['id'],
    ]);
    if (!$result['ok']) {
        remember_input();
        foreach ($result['errors'] as $err) flash('error', $err);
        redirect('admin/walkin.php');
    }
    $id = $result['id'];
    $b = get_booking($id);

    if ($payAmount > 0) {
        $payAmount = min($payAmount, (float) $b['total_amount']);
        $type = $payAmount + 0.001 >= (float) $b['total_amount'] ? 'full' : 'downpayment';
        db_exec("INSERT INTO payments (booking_id, amount, method, payment_type, reference_no, status, recorded_by, verified_by, verified_at)
                 VALUES (?, ?, ?, ?, ?, 'verified', ?, ?, NOW())",
            [$id, $payAmount, $method, $type, post('pay_reference') ?: null, (int) $me['id'], (int) $me['id']]);
        audit('payment.record', 'booking', $id, $b['booking_code'] . ' - ' . money($payAmount) . ' ' . $method . ' (walk-in)');
    }

    // Walk-ins are confirmed right away; checked in too if they're here today
    db_exec("UPDATE bookings SET status = 'confirmed', expires_at = NULL WHERE id = ?", [$id]);
    audit('booking.confirmed', 'booking', $id, $b['booking_code'] . ' (walk-in)');
    if (post('check_in_now') && $b['check_in'] === date('Y-m-d')) {
        db_exec("UPDATE bookings SET status = 'checked_in' WHERE id = ?", [$id]);
        audit('booking.checked_in', 'booking', $id, $b['booking_code']);
    }
    if ($b['guest_email']) notify_booking($id, 'confirmed');

    clear_old();
    flash('success', 'Walk-in booking ' . $result['code'] . ' saved.');
    redirect('admin/booking.php?id=' . $id);
}

$prefill = [
    'accommodation_id' => get('accommodation_id'),
    'check_in'         => get('check_in') ?: date('Y-m-d'),
    'check_out'        => get('check_out'),
    'num_adults'       => (int) get('guests') ?: 2,
];
$formMode = 'walkin';
$pageTitle = 'Walk-in booking';
$activeAdmin = 'walkin';
include __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="mb-1">Walk-in booking</h1>
<p class="text-muted mb-4">For guests at the gate or booking by phone. The booking is saved as <strong>confirmed</strong>, and the payment you enter is recorded as verified.</p>
<?php include __DIR__ . '/../includes/booking_form.php'; ?>
<?php
$extraScripts = '<script src="' . e(url('assets/js/booking.js')) . '?v=' . filemtime(__DIR__ . '/../assets/js/booking.js') . '"></script>';
include __DIR__ . '/../includes/admin_footer.php';

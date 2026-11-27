<?php
require __DIR__ . '/../includes/bootstrap.php';
$me = require_staff();

$id = (int) get('id');
$b = get_booking($id);
if (!$b) {
    flash('error', 'Booking not found.');
    redirect('admin/bookings.php');
}
$self = 'admin/booking.php?id=' . $id;

if (is_post()) {
    csrf_check();
    $action = post('action');
    $err = null;
    $ok = null;

    switch ($action) {
        case 'verify_payment':
            $err = verify_payment((int) post('payment_id'));
            if (!$err) $ok = 'Payment verified.' . (get_booking($id)['status'] === 'confirmed' && $b['status'] === 'pending' ? ' Booking is now confirmed and the guest was emailed.' : '');
            break;

        case 'reject_payment':
            $err = reject_payment((int) post('payment_id'), post('reason'));
            $ok = 'Receipt rejected. The guest was asked to upload a new one.';
            break;

        case 'record_payment':
            $amount = round((float) post('amount'), 2);
            $method = in_array(post('method'), ['cash', 'gcash', 'bank'], true) ? post('method') : 'cash';
            if ($amount <= 0) { $err = 'Enter an amount greater than zero.'; break; }
            if ($amount > balance_due($b) + 0.001) { $err = 'That is more than the balance of ' . money(balance_due($b)) . '.'; break; }
            if (!in_array($b['status'], ['pending', 'confirmed', 'checked_in'], true)) { $err = 'Payments can only be recorded on active bookings.'; break; }
            $type = (float) $b['amount_paid'] <= 0 ? ($amount + 0.001 >= (float) $b['total_amount'] ? 'full' : 'downpayment') : 'balance';
            db_exec("INSERT INTO payments (booking_id, amount, method, payment_type, reference_no, status, recorded_by, verified_by, verified_at)
                     VALUES (?, ?, ?, ?, ?, 'verified', ?, ?, NOW())",
                [$id, $amount, $method, $type, post('reference_no') ?: null, (int) $me['id'], (int) $me['id']]);
            audit('payment.record', 'booking', $id, $b['booking_code'] . ' - ' . money($amount) . ' ' . $method);
            $ok = 'Payment of ' . money($amount) . ' recorded.';
            $fresh = get_booking($id);
            if ($fresh['status'] === 'pending' && (float) $fresh['amount_paid'] + 0.001 >= (float) $fresh['downpayment_due']) {
                $err = change_booking_status($id, 'confirmed');
                if (!$err) $ok .= ' Booking confirmed.';
            }
            break;

        case 'check_in':
            if ($b['check_in'] > date('Y-m-d')) { $err = 'This booking starts on ' . fmt_date($b['check_in']) . '. You can check in from that day.'; break; }
            $err = change_booking_status($id, 'checked_in');
            $ok = $b['guest_name'] . ' is checked in.';
            break;

        case 'check_out':
            // Optionally take the remaining balance in the same step
            $pay = round((float) post('balance_amount'), 2);
            if ($pay > 0) {
                if ($pay > balance_due($b) + 0.001) { $err = 'That is more than the balance.'; break; }
                $method = in_array(post('method'), ['cash', 'gcash', 'bank'], true) ? post('method') : 'cash';
                db_exec("INSERT INTO payments (booking_id, amount, method, payment_type, reference_no, status, recorded_by, verified_by, verified_at)
                         VALUES (?, ?, ?, 'balance', ?, 'verified', ?, ?, NOW())",
                    [$id, $pay, $method, post('reference_no') ?: null, (int) $me['id'], (int) $me['id']]);
                audit('payment.record', 'booking', $id, $b['booking_code'] . ' - balance ' . money($pay) . ' ' . $method);
            }
            $err = change_booking_status($id, 'completed');
            $ok = 'Checked out and completed. Receipt is ready.';
            break;

        case 'cancel':
            $err = change_booking_status($id, 'cancelled', post('reason'));
            $ok = 'Booking cancelled and dates released.';
            break;

        case 'reject':
            $err = change_booking_status($id, 'rejected', post('reason'));
            $ok = 'Booking rejected and dates released.';
            break;

        case 'notes':
            db_exec('UPDATE bookings SET staff_notes = ? WHERE id = ?', [post('staff_notes') ?: null, $id]);
            audit('booking.notes', 'booking', $id, $b['booking_code']);
            $ok = 'Notes saved.';
            break;

        case 'extend':
            if ($b['status'] !== 'pending') { $err = 'Only pending bookings have a payment deadline.'; break; }
            $hours = max(1, min(168, (int) post('hours')));
            db_exec('UPDATE bookings SET expires_at = DATE_ADD(GREATEST(COALESCE(expires_at, NOW()), NOW()), INTERVAL ? HOUR) WHERE id = ?', [$hours, $id]);
            audit('booking.extend', 'booking', $id, $b['booking_code'] . " +{$hours}h");
            $ok = "Payment deadline extended by $hours hours.";
            break;

        case 'reschedule':
            $err = reschedule_booking($b, (int) post('accommodation_id'), post('check_in'), post('check_out'));
            $ok = 'Booking moved. The price was recalculated for the new dates.';
            break;

        default:
            $err = 'Unknown action.';
    }
    flash($err ? 'error' : 'success', $err ?: $ok);
    redirect($self);
}

/** Move a booking to other dates/unit. Re-checks availability and recalculates the price. */
function reschedule_booking(array $b, int $accId, string $in, string $out): ?string
{
    if (!in_array($b['status'], ['pending', 'confirmed'], true)) return 'Only pending or confirmed bookings can be moved.';
    $acc = db_one('SELECT * FROM accommodations WHERE id = ? AND is_active = 1', [$accId]);
    if (!$acc) return 'Choose an active unit.';
    [$in, $out] = normalize_stay($acc, $in, $out);
    if (!valid_date($in) || !valid_date($out) || $out <= $in) return 'Choose valid dates.';
    if ($in < date('Y-m-d')) return 'The new date cannot be in the past.';
    if ($b['num_adults'] + $b['num_kids'] > $acc['capacity']) return $acc['name'] . ' only fits ' . $acc['capacity'] . ' guests.';

    $addons = $food = [];
    foreach (booking_items((int) $b['id']) as $it) {
        if ($it['item_type'] === 'addon') $addons[$it['ref_id']] = $it['quantity'];
        if ($it['item_type'] === 'food') $food[$it['ref_id']] = $it['quantity'];
    }
    $quote = calculate_quote($acc, $in, $out, (int) $b['num_adults'], (int) $b['num_kids'], (bool) $b['include_swimming'], $addons, $food);

    $conn = db();
    $conn->begin_transaction();
    try {
        db_one('SELECT id FROM accommodations WHERE id = ? FOR UPDATE', [$accId]);
        if (!is_available($accId, $in, $out, (int) $b['id'], true)) {
            $conn->rollback();
            return $acc['name'] . ' is not free on those dates.';
        }
        if ($stockErrors = addon_stock_errors($addons, $in, $out, (int) $b['id'])) {
            $conn->rollback();
            return 'Cannot move the booking: ' . implode(' ', $stockErrors);
        }
        db_exec('UPDATE bookings SET accommodation_id = ?, check_in = ?, check_out = ?, total_amount = ?, downpayment_due = ? WHERE id = ?',
            [$accId, $in, $out, $quote['total'], $quote['downpayment'], (int) $b['id']]);
        db_exec('DELETE FROM booking_items WHERE booking_id = ?', [(int) $b['id']]);
        foreach ($quote['items'] as $it) {
            db_exec('INSERT INTO booking_items (booking_id, item_type, ref_id, description, quantity, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [(int) $b['id'], $it['item_type'], $it['ref_id'], $it['description'], $it['quantity'], $it['unit_price'], $it['amount']]);
        }
        $conn->commit();
    } catch (Throwable $ex) {
        $conn->rollback();
        return 'Could not move the booking: ' . $ex->getMessage();
    }
    audit('booking.reschedule', 'booking', (int) $b['id'], $b['booking_code'] . ': ' . $b['accommodation_name'] . ' ' . $b['check_in'] . ' -> ' . $acc['name'] . ' ' . $in . ' (total ' . $quote['total'] . ')');
    return null;
}

$items = booking_items($id);
$payments = booking_payments($id);
$history = db_all("SELECT l.*, u.name AS user_name FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
                   WHERE l.target_type = 'booking' AND l.target_id = ? ORDER BY l.created_at DESC, l.id DESC", [$id]);
$emails = db_all('SELECT id, to_email, subject, created_at FROM email_log WHERE booking_id = ? ORDER BY id DESC', [$id]);
$units = active_accommodations();
$balance = balance_due($b);
$isNight = $b['type'] === 'overnight_room';

$pageTitle = $b['booking_code'];
$activeAdmin = 'bookings';
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
  <div>
    <a href="bookings.php" class="small"><i class="bi bi-arrow-left"></i> All bookings</a>
    <h1 class="mt-1"><?= e($b['booking_code']) ?> <?= status_badge($b['status']) ?>
      <?= $b['source'] === 'walk_in' ? '<span class="badge text-bg-light fs-6">walk-in</span>' : '' ?></h1>
    <?php if ($b['status_reason'] && !in_array($b['status'], BLOCKING_STATUSES, true)): ?><p class="text-muted mb-0">Reason: <?= e($b['status_reason']) ?></p><?php endif; ?>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="<?= e(url('receipt.php?code=' . urlencode($b['booking_code']))) ?>" target="_blank"><i class="bi bi-printer"></i> Receipt</a>
  </div>
</div>

<div class="row g-4">
  <div class="col-xl-8">
    <div class="card p-3 mb-4">
      <div class="row">
        <div class="col-md-6">
          <h6 class="text-muted small">Guest</h6>
          <p class="mb-1 fw-semibold"><?= e($b['guest_name']) ?></p>
          <p class="mb-1 small"><i class="bi bi-telephone"></i> <a href="tel:<?= e($b['guest_phone']) ?>"><?= e($b['guest_phone']) ?></a></p>
          <?php if ($b['guest_email']): ?><p class="mb-1 small"><i class="bi bi-envelope"></i> <?= e($b['guest_email']) ?></p><?php endif; ?>
          <?php if ($b['user_id']): ?><p class="mb-0 small"><a href="guest.php?id=<?= (int) $b['user_id'] ?>">View guest history</a></p><?php endif; ?>
        </div>
        <div class="col-md-6">
          <h6 class="text-muted small mt-3 mt-md-0">Stay</h6>
          <p class="mb-1 fw-semibold"><?= e($b['accommodation_name']) ?></p>
          <p class="mb-1 small"><i class="bi bi-calendar3"></i> <?= e(stay_label($b)) ?></p>
          <p class="mb-1 small"><i class="bi bi-people"></i> <?= (int) $b['num_adults'] ?> adult(s), <?= (int) $b['num_kids'] ?> kid(s)<?= $b['include_swimming'] ? ' &middot; with swimming' : '' ?></p>
          <p class="mb-0 small text-muted">Booked <?= e(fmt_datetime($b['created_at'])) ?><?= $b['created_by_name'] ? ' by ' . e($b['created_by_name']) : '' ?></p>
        </div>
      </div>
      <?php if ($b['special_requests']): ?>
        <div class="alert alert-light border mt-3 mb-0 small"><strong>Guest requests:</strong> <?= nl2br(e($b['special_requests'])) ?></div>
      <?php endif; ?>
    </div>

    <div class="card p-3 mb-4" id="payments">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h5 class="mb-0">Payments</h5>
        <span class="small">Paid <strong class="text-success"><?= money($b['amount_paid']) ?></strong> of <?= money($b['total_amount']) ?> &middot; Balance <strong><?= money($balance) ?></strong></span>
      </div>
      <?php if (!$payments): ?><p class="text-muted small">No payments yet. Downpayment due: <?= money($b['downpayment_due']) ?></p><?php endif; ?>
      <?php foreach ($payments as $p): ?>
        <div class="border rounded-3 p-3 mb-3 <?= $p['status'] === 'pending' ? 'border-warning border-2' : '' ?>">
          <div class="row g-3">
            <div class="col-md-<?= $p['proof_path'] ? '6' : '12' ?>">
              <div class="d-flex justify-content-between"><strong class="fs-5"><?= money($p['amount']) ?></strong><?= payment_badge($p['status']) ?></div>
              <dl class="row small mb-0 mt-2">
                <dt class="col-5">Method</dt><dd class="col-7"><?= e(payment_method_label($p['method'])) ?> &middot; <?= e($p['payment_type']) ?></dd>
                <dt class="col-5">Reference</dt><dd class="col-7"><?= e($p['reference_no'] ?: '—') ?></dd>
                <dt class="col-5">Sent / recorded</dt><dd class="col-7"><?= e(fmt_datetime($p['paid_at'])) ?><?= $p['recorded_by_name'] ? '<br>by ' . e($p['recorded_by_name']) : '' ?></dd>
                <?php if ($p['verified_by_name']): ?><dt class="col-5"><?= $p['status'] === 'rejected' ? 'Rejected' : 'Verified' ?> by</dt><dd class="col-7"><?= e($p['verified_by_name']) ?>, <?= e(fmt_datetime($p['verified_at'])) ?></dd><?php endif; ?>
                <?php if ($p['reject_reason']): ?><dt class="col-5">Reason</dt><dd class="col-7 text-danger"><?= e($p['reject_reason']) ?></dd><?php endif; ?>
              </dl>
              <?php if ($p['status'] === 'pending'): ?>
                <div class="d-flex gap-2 mt-3 flex-wrap">
                  <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="verify_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                    <button class="btn btn-success"><i class="bi bi-check-lg"></i> Approve payment</button></form>
                  <button class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#rej<?= (int) $p['id'] ?>"><i class="bi bi-x-lg"></i> Reject</button>
                </div>
                <form method="post" class="collapse mt-2" id="rej<?= (int) $p['id'] ?>">
                  <?= csrf_field() ?><input type="hidden" name="action" value="reject_payment"><input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                  <div class="input-group input-group-sm">
                    <input name="reason" class="form-control" list="rejectReasons" placeholder="Reason (the guest will see this)" required>
                    <button class="btn btn-danger">Reject receipt</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
            <?php if ($p['proof_path']): $src = url('receipt_file.php?id=' . (int) $p['id']); ?>
              <div class="col-md-6 text-center">
                <?php if (str_ends_with(strtolower($p['proof_path']), '.pdf')): ?>
                  <a class="btn btn-outline-primary mt-4" href="<?= e($src) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Open PDF receipt</a>
                <?php else: ?>
                  <a href="<?= e($src) ?>" target="_blank"><img src="<?= e($src) ?>" class="receipt-preview" alt="Receipt"></a>
                  <div class="small text-muted">Click to open full size</div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <datalist id="rejectReasons">
        <option value="Amount does not match the downpayment">
        <option value="Reference number not found in our GCash history">
        <option value="Receipt image is blurry or cropped">
        <option value="Receipt is for a different booking">
      </datalist>
      <?php if (in_array($b['status'], ['pending', 'confirmed', 'checked_in'], true) && $balance > 0): ?>
        <button class="btn btn-sm btn-outline-primary align-self-start" data-bs-toggle="collapse" data-bs-target="#recordPay"><i class="bi bi-plus"></i> Record a payment (cash / GCash / bank)</button>
        <form method="post" class="collapse mt-3" id="recordPay">
          <?= csrf_field() ?><input type="hidden" name="action" value="record_payment">
          <div class="row g-2">
            <div class="col-md-3"><input type="number" step="0.01" min="0.01" max="<?= $balance ?>" name="amount" class="form-control form-control-sm" placeholder="Amount" value="<?= (float) $b['amount_paid'] > 0 ? $balance : $b['downpayment_due'] ?>" required></div>
            <div class="col-md-3"><select name="method" class="form-select form-select-sm"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank">Bank</option></select></div>
            <div class="col-md-4"><input name="reference_no" class="form-control form-control-sm" placeholder="Reference no. (optional)" maxlength="60"></div>
            <div class="col-md-2 d-grid"><button class="btn btn-sm btn-primary">Save</button></div>
          </div>
          <p class="small text-muted mt-1 mb-0">Payments recorded by staff count as verified immediately.</p>
        </form>
      <?php endif; ?>
    </div>

    <div class="card p-3 mb-4">
      <h5>Price breakdown</h5>
      <table class="table table-sm small mb-0">
        <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Price</th><th class="text-end">Amount</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr><td><?= e($it['description']) ?> <?= $it['item_type'] === 'food' ? '<span class="badge text-bg-light">food pre-order</span>' : '' ?></td><td class="text-end"><?= (int) $it['quantity'] ?></td><td class="text-end"><?= money($it['unit_price']) ?></td><td class="text-end"><?= money($it['amount']) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr><th colspan="3" class="text-end">Total</th><th class="text-end"><?= money($b['total_amount']) ?></th></tr>
          <tr><td colspan="3" class="text-end text-muted">Downpayment required</td><td class="text-end text-muted"><?= money($b['downpayment_due']) ?></td></tr>
        </tfoot>
      </table>
    </div>

    <div class="card p-3 mb-4">
      <h5>Staff notes</h5>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="notes">
        <textarea name="staff_notes" class="form-control mb-2" rows="3" placeholder="Only staff can see these notes, e.g. refund handled outside the system"><?= e($b['staff_notes']) ?></textarea>
        <button class="btn btn-sm btn-outline-primary">Save notes</button>
      </form>
    </div>
  </div>

  <div class="col-xl-4">
    <div class="card p-3 mb-4">
      <h5 class="mb-3">Actions</h5>
      <?php if ($b['status'] === 'pending'): ?>
        <p class="small mb-2"><i class="bi bi-clock-history"></i> Payment deadline: <strong><?= e(fmt_datetime($b['expires_at'])) ?></strong></p>
        <?php if ($b['pending_payments']): ?><p class="small text-muted">The guest uploaded a receipt, so this booking won't expire while it waits for review.</p><?php endif; ?>
        <form method="post" class="input-group input-group-sm mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="extend">
          <select name="hours" class="form-select"><option value="12">+12 hours</option><option value="24" selected>+24 hours</option><option value="48">+48 hours</option></select>
          <button class="btn btn-outline-primary">Extend deadline</button></form>
      <?php endif; ?>

      <?php if ($b['status'] === 'confirmed'): ?>
        <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="action" value="check_in">
          <button class="btn btn-primary w-100" <?= $b['check_in'] > date('Y-m-d') ? 'disabled title="Available on the arrival date"' : '' ?>><i class="bi bi-box-arrow-in-right"></i> Check in guest</button>
          <?php if ($b['check_in'] > date('Y-m-d')): ?><div class="form-text">Available from <?= e(fmt_date($b['check_in'])) ?>.</div><?php endif; ?>
        </form>
      <?php endif; ?>

      <?php if ($b['status'] === 'checked_in'): ?>
        <form method="post" id="checkout" class="mb-3 border rounded-3 p-2"><?= csrf_field() ?><input type="hidden" name="action" value="check_out">
          <p class="small fw-semibold mb-2">Check out &amp; complete</p>
          <?php if ($balance > 0): ?>
            <p class="small mb-2">Collect the remaining balance:</p>
            <div class="input-group input-group-sm mb-2">
              <span class="input-group-text">₱</span>
              <input type="number" step="0.01" name="balance_amount" class="form-control" value="<?= number_format($balance, 2, '.', '') ?>" min="0" max="<?= $balance ?>">
              <select name="method" class="form-select"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="bank">Bank</option></select>
            </div>
            <input name="reference_no" class="form-control form-control-sm mb-2" placeholder="Reference no. (optional)">
          <?php else: ?>
            <p class="small text-success mb-2"><i class="bi bi-check-circle"></i> Fully paid.</p>
          <?php endif; ?>
          <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-right"></i> Check out<?= $balance > 0 ? ' &amp; record balance' : '' ?></button>
        </form>
      <?php endif; ?>

      <?php if (in_array($b['status'], ['pending', 'confirmed'], true)): ?>
        <button class="btn btn-outline-secondary w-100 mb-2" data-bs-toggle="collapse" data-bs-target="#moveForm"><i class="bi bi-arrow-left-right"></i> Reschedule / change unit</button>
        <form method="post" class="collapse border rounded-3 p-2 mb-3" id="moveForm"><?= csrf_field() ?><input type="hidden" name="action" value="reschedule">
          <select name="accommodation_id" class="form-select form-select-sm mb-2">
            <?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === (int) $b['accommodation_id'] ? 'selected' : '' ?>><?= e($u['name']) ?> (<?= (int) $u['capacity'] ?> pax)</option><?php endforeach; ?>
          </select>
          <div class="row g-1 mb-2">
            <div class="col"><label class="small">Check-in / date</label><input type="date" name="check_in" class="form-control form-control-sm" value="<?= e($b['check_in']) ?>" min="<?= date('Y-m-d') ?>" required></div>
            <div class="col"><label class="small">Check-out (rooms)</label><input type="date" name="check_out" class="form-control form-control-sm" value="<?= e($b['check_out']) ?>"></div>
          </div>
          <button class="btn btn-sm btn-primary w-100">Move booking</button>
          <div class="form-text">Availability is checked again and the price is recalculated.</div>
        </form>

        <button class="btn btn-outline-danger w-100 mb-2" data-bs-toggle="collapse" data-bs-target="#cancelForm"><i class="bi bi-x-circle"></i> Cancel booking</button>
        <form method="post" class="collapse mb-2" id="cancelForm"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
          <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" placeholder="Reason (e.g. guest requested; refund of ₱... handled via GCash)" required></textarea>
          <button class="btn btn-sm btn-danger w-100" onclick="return confirm('Cancel this booking and free the dates?')">Confirm cancel</button>
        </form>
      <?php endif; ?>

      <?php if ($b['status'] === 'pending'): ?>
        <button class="btn btn-link text-danger w-100 btn-sm" data-bs-toggle="collapse" data-bs-target="#rejectForm">Reject booking (e.g. suspicious / farm closed)</button>
        <form method="post" class="collapse" id="rejectForm"><?= csrf_field() ?><input type="hidden" name="action" value="reject">
          <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" placeholder="Reason shown to the guest" required></textarea>
          <button class="btn btn-sm btn-danger w-100">Reject booking</button>
        </form>
      <?php endif; ?>

      <?php if (!in_array($b['status'], BLOCKING_STATUSES, true)): ?>
        <p class="small text-muted mb-0">This booking is <?= e(strtolower(BOOKING_STATUSES[$b['status']][0])) ?>. No further actions.</p>
      <?php endif; ?>
    </div>

    <div class="card p-3 mb-4">
      <h5 class="mb-3">History</h5>
      <ul class="list-unstyled small mb-0">
        <?php foreach ($history as $h): ?>
          <li class="mb-2 pb-2 border-bottom">
            <strong><?= e(str_replace(['booking.', 'payment.', '_'], ['', 'payment ', ' '], $h['action'])) ?></strong>
            <span class="text-muted">&middot; <?= e($h['user_name'] ?? 'System') ?></span><br>
            <span class="text-muted"><?= e(fmt_datetime($h['created_at'])) ?></span>
            <?php if ($h['details']): ?><br><span><?= e($h['details']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
        <?php if (!$history): ?><li class="text-muted">No history yet.</li><?php endif; ?>
      </ul>
    </div>

    <?php if ($emails): ?>
      <div class="card p-3 mb-4">
        <h5 class="mb-3">Emails sent</h5>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($emails as $em): ?>
            <li class="mb-1"><a href="emails.php?id=<?= (int) $em['id'] ?>"><?= e($em['subject']) ?></a><br><span class="text-muted"><?= e(fmt_datetime($em['created_at'])) ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php';

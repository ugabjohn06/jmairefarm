<?php
// Booking summary + payment page for guests.
require __DIR__ . '/includes/bootstrap.php';

$b = get_booking_by_code((string) get('code'));
if (!$b) {
    flash('warning', 'Booking not found.');
    redirect(is_logged_in() ? 'my_bookings.php' : 'find_booking.php');
}
if (!can_view_booking($b)) {
    if (!is_logged_in()) require_login();
    http_response_code(403);
    include __DIR__ . '/includes/403.php';
    exit;
}
$id = (int) $b['id'];
$self = 'booking.php?code=' . urlencode($b['booking_code']);
$canPay = in_array($b['status'], ['pending', 'confirmed'], true) && balance_due($b) > 0 && (int) $b['pending_payments'] === 0;

if (is_post()) {
    csrf_check();
    $action = post('action');

    if ($action === 'upload' && $canPay) {
        $amount = round((float) post('amount'), 2);
        $method = in_array(post('method'), ['gcash', 'bank'], true) ? post('method') : 'gcash';
        $ref = mb_substr(preg_replace('/[^A-Za-z0-9\-\s]/', '', post('reference_no')), 0, 60);
        $err = null;
        if ($amount <= 0 || $amount > balance_due($b) + 0.001) {
            $err = 'Please enter the amount you paid (up to ' . money(balance_due($b)) . ').';
        } elseif ($ref === '') {
            $err = 'Please enter the reference number from your receipt.';
        }
        if (!$err) {
            [$file, $err] = store_receipt_upload($_FILES['proof'] ?? null);
        }
        if ($err) {
            flash('error', $err);
            redirect($self);
        }
        $type = (float) $b['amount_paid'] <= 0 ? ($amount + 0.001 >= (float) $b['total_amount'] ? 'full' : 'downpayment') : 'balance';
        db_exec('INSERT INTO payments (booking_id, amount, method, payment_type, reference_no, proof_path, status, recorded_by) VALUES (?, ?, ?, ?, ?, ?, \'pending\', ?)',
            [$id, $amount, $method, $type, $ref, $file, current_user()['id'] ?? null]);
        audit('payment.upload', 'booking', $id, $b['booking_code'] . ' - ' . money($amount) . ' ' . $method . ' ref ' . $ref);
        flash('success', 'Receipt uploaded! Our staff will check it shortly. We will email you once it is verified.');
        redirect($self);
    }

    if ($action === 'cancel' && $b['status'] === 'pending') {
        $err = change_booking_status($id, 'cancelled', 'Cancelled by guest' . (post('reason') ? ': ' . post('reason') : ''));
        flash($err ? 'error' : 'success', $err ?: 'Your booking was cancelled and the date was released.');
        redirect($self);
    }
    redirect($self);
}

$items = booking_items($id);
$payments = booking_payments($id);
$lastRejected = null;
foreach ($payments as $p) if ($p['status'] === 'rejected') $lastRejected = $p;
$pageTitle = 'Booking ' . $b['booking_code'];
$activeNav = 'mine';

$steps = ['pending' => 'Booked', 'paid' => 'Receipt sent', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked in', 'completed' => 'Completed'];
$order = ['pending' => 0, 'confirmed' => 2, 'checked_in' => 3, 'completed' => 4];
$cur = $order[$b['status']] ?? -1;
if ($b['status'] === 'pending' && $b['pending_payments'] > 0) $cur = 1;

include __DIR__ . '/includes/header.php';
?>
<div class="container py-4 py-lg-5" style="max-width:1080px">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
      <p class="text-muted small mb-0">Booking code</p>
      <h1 class="mb-0"><?= e($b['booking_code']) ?> <?= status_badge($b['status']) ?></h1>
    </div>
    <div class="d-flex gap-2">
      <?php if ($payments && (float) $b['amount_paid'] > 0): ?>
        <a class="btn btn-outline-primary" href="<?= e(url('receipt.php?code=' . urlencode($b['booking_code']))) ?>" target="_blank"><i class="bi bi-receipt"></i> Receipt</a>
      <?php endif; ?>
      <?php if (is_logged_in() && !is_staff()): ?>
        <a class="btn btn-outline-secondary" href="<?= e(url('my_bookings.php')) ?>">My bookings</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($cur >= 0): ?>
    <div class="status-steps mb-4">
      <?php $i = 0; foreach ($steps as $k => $label): ?>
        <div class="step <?= $i < $cur ? 'done' : ($i === $cur ? 'current' : '') ?>"><?= e($label) ?></div>
      <?php $i++; endforeach; ?>
    </div>
  <?php elseif ($b['status_reason']): ?>
    <div class="alert alert-secondary"><strong><?= e(BOOKING_STATUSES[$b['status']][0]) ?>:</strong> <?= e($b['status_reason']) ?></div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <?php if ($b['status'] === 'pending'): ?>
        <?php if ($b['pending_payments'] > 0): ?>
          <div class="alert alert-info"><i class="bi bi-hourglass-split"></i> We received your receipt and our staff is checking it. Your date stays on hold in the meantime.</div>
        <?php else: ?>
          <?php if ($lastRejected): ?>
            <div class="alert alert-danger"><strong>Your last receipt was not accepted.</strong><br>Reason: <?= e($lastRejected['reject_reason']) ?><br>Please upload a new one.</div>
          <?php endif; ?>
          <div class="alert alert-warning d-flex justify-content-between flex-wrap gap-2 align-items-center">
            <span><i class="bi bi-clock-history"></i> Pay the downpayment before <strong><?= e(fmt_datetime($b['expires_at'])) ?></strong> or the date is released.</span>
            <span class="countdown" data-deadline="<?= e(date('c', strtotime($b['expires_at']))) ?>"></span>
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <?php if ($canPay): ?>
        <div class="card p-3 p-md-4 mb-4">
          <h5><?= (float) $b['amount_paid'] > 0 ? 'Pay your balance' : 'Pay your downpayment' ?></h5>
          <p class="mb-3">Amount to send: <strong class="fs-5 text-primary"><?= money((float) $b['amount_paid'] > 0 ? balance_due($b) : $b['downpayment_due']) ?></strong>
            <?php if ((float) $b['amount_paid'] <= 0): ?><span class="small text-muted">(or the full <?= money($b['total_amount']) ?>)</span><?php endif; ?></p>
          <div class="row g-3 mb-3">
            <div class="col-md-6"><div class="pay-box h-100">
              <div class="fw-semibold mb-1"><i class="bi bi-phone text-primary"></i> GCash</div>
              <div class="fs-5"><?= e(setting('gcash_number')) ?></div>
              <div class="small text-muted"><?= e(setting('gcash_name')) ?></div>
            </div></div>
            <div class="col-md-6"><div class="pay-box h-100">
              <div class="fw-semibold mb-1"><i class="bi bi-bank text-primary"></i> Bank transfer</div>
              <div><?= e(setting('bank_name')) ?> &middot; <?= e(setting('bank_account_no')) ?></div>
              <div class="small text-muted"><?= e(setting('bank_account_name')) ?></div>
            </div></div>
          </div>
          <p class="small text-muted">Put your booking code <strong><?= e($b['booking_code']) ?></strong> in the message/notes when you send.</p>
          <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="hidden" name="MAX_FILE_SIZE" value="<?= MAX_UPLOAD_BYTES ?>">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Amount paid</label>
                <input type="number" step="0.01" min="1" max="<?= balance_due($b) ?>" name="amount" class="form-control" required
                       value="<?= number_format((float) $b['amount_paid'] > 0 ? balance_due($b) : (float) $b['downpayment_due'], 2, '.', '') ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label">Paid via</label>
                <select name="method" class="form-select"><option value="gcash">GCash</option><option value="bank">Bank transfer</option></select>
              </div>
              <div class="col-md-4">
                <label class="form-label">Reference no.</label>
                <input type="text" name="reference_no" class="form-control" maxlength="60" required>
              </div>
              <div class="col-12">
                <label class="form-label">Receipt screenshot or PDF</label>
                <input type="file" name="proof" class="form-control" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
                <div class="form-text">JPG, PNG or PDF, up to <?= MAX_UPLOAD_BYTES / 1024 / 1024 ?> MB.</div>
              </div>
            </div>
            <button class="btn btn-primary mt-3"><i class="bi bi-upload"></i> Upload receipt</button>
          </form>
        </div>
      <?php endif; ?>

      <div class="card p-3 p-md-4 mb-4">
        <h5 class="mb-3">Booking details</h5>
        <dl class="row mb-0 small">
          <dt class="col-sm-4">Accommodation</dt><dd class="col-sm-8"><?= e($b['accommodation_name']) ?> <span class="text-muted">(<?= e(type_label($b['type'])) ?>)</span></dd>
          <dt class="col-sm-4">Date</dt><dd class="col-sm-8"><?= e(stay_label($b)) ?></dd>
          <dt class="col-sm-4"><?= $b['type'] === 'overnight_room' ? 'Times' : 'Hours' ?></dt>
          <dd class="col-sm-8"><?= $b['type'] === 'overnight_room' ? 'Check-in ' . e(setting('checkin_time')) . ', check-out ' . e(setting('checkout_time')) : e(setting('day_hours')) ?></dd>
          <dt class="col-sm-4">Guests</dt><dd class="col-sm-8"><?= (int) $b['num_adults'] ?> adult(s), <?= (int) $b['num_kids'] ?> kid(s)</dd>
          <dt class="col-sm-4">Name</dt><dd class="col-sm-8"><?= e($b['guest_name']) ?></dd>
          <dt class="col-sm-4">Contact</dt><dd class="col-sm-8"><?= e($b['guest_phone']) ?><?= $b['guest_email'] ? ' &middot; ' . e($b['guest_email']) : '' ?></dd>
          <?php if ($b['special_requests']): ?><dt class="col-sm-4">Requests</dt><dd class="col-sm-8"><?= nl2br(e($b['special_requests'])) ?></dd><?php endif; ?>
          <dt class="col-sm-4">Booked on</dt><dd class="col-sm-8"><?= e(fmt_datetime($b['created_at'])) ?></dd>
        </dl>
      </div>

      <?php if ($payments): ?>
        <div class="card p-3 p-md-4 mb-4">
          <h5 class="mb-3">Payments</h5>
          <div class="table-responsive">
            <table class="table table-sm mb-0">
              <thead><tr><th>Date</th><th>Method</th><th>Ref</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($payments as $p): ?>
                  <tr>
                    <td><?= e(fmt_date($p['paid_at'])) ?></td>
                    <td><?= e(payment_method_label($p['method'])) ?></td>
                    <td><?= e($p['reference_no']) ?></td>
                    <td class="text-end"><?= money($p['amount']) ?></td>
                    <td><?= payment_badge($p['status']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($b['status'] === 'pending'): ?>
        <form method="post" onsubmit="return confirm('Cancel this booking? The date will be released.');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="cancel">
          <button class="btn btn-link text-danger px-0"><i class="bi bi-x-circle"></i> Cancel this booking</button>
        </form>
      <?php elseif ($b['status'] === 'confirmed'): ?>
        <p class="small text-muted">Need to cancel or move your date? Message us at <?= e(setting('contact_phone')) ?> and mention your booking code.</p>
      <?php endif; ?>
    </div>

    <div class="col-lg-5">
      <div class="card p-3 p-md-4 summary-card">
        <h5 class="mb-3">Price breakdown</h5>
        <?php foreach ($items as $it): ?>
          <div class="summary-line"><span><?= e($it['description']) ?><?= $it['quantity'] > 1 && $it['item_type'] !== 'accommodation' ? ' &times; ' . (int) $it['quantity'] : '' ?></span><span><?= money($it['amount']) ?></span></div>
        <?php endforeach; ?>
        <div class="summary-line summary-total fs-6"><span>Total</span><span><?= money($b['total_amount']) ?></span></div>
        <div class="summary-line"><span>Downpayment required</span><span><?= money($b['downpayment_due']) ?></span></div>
        <div class="summary-line text-success"><span>Paid (verified)</span><span><?= money($b['amount_paid']) ?></span></div>
        <div class="summary-line fw-semibold"><span>Balance</span><span><?= money(balance_due($b)) ?></span></div>
      </div>
    </div>
  </div>
</div>
<?php
$extraScripts = <<<HTML
<script>
document.querySelectorAll('.countdown').forEach(function (el) {
  const end = new Date(el.dataset.deadline).getTime();
  function tick() {
    const s = Math.max(0, Math.floor((end - Date.now()) / 1000));
    const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
    el.textContent = s > 0 ? h + 'h ' + String(m).padStart(2, '0') + 'm ' + String(sec).padStart(2, '0') + 's left' : 'Deadline passed';
  }
  tick(); setInterval(tick, 1000);
});
</script>
HTML;
include __DIR__ . '/includes/footer.php';

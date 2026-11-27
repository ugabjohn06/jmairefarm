<?php
require __DIR__ . '/../includes/bootstrap.php';
require_staff();

$today = date('Y-m-d');
$arrivals = db_all("SELECT b.*, a.name AS accommodation_name, a.type,
                      (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.booking_id = b.id AND p.status='verified') AS amount_paid
                    FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
                    WHERE b.check_in = ? AND b.status IN ('pending','confirmed','checked_in') ORDER BY a.sort_order", [$today]);
$departures = db_all("SELECT b.*, a.name AS accommodation_name, a.type,
                      (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.booking_id = b.id AND p.status='verified') AS amount_paid
                      FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
                      WHERE b.status = 'checked_in'
                        -- day-use guests leave the day they arrive; overnight guests on their check-out day (or overdue)
                        AND ((a.type = 'day_cottage' AND b.check_in <= ?) OR (a.type = 'overnight_room' AND b.check_out <= ?))
                      ORDER BY a.sort_order", [$today, $today]);

$toVerify = db_all("SELECT p.*, b.booking_code, b.guest_name, b.downpayment_due, b.status AS booking_status, b.check_in
                    FROM payments p JOIN bookings b ON b.id = p.booking_id
                    WHERE p.status = 'pending' AND b.status IN ('pending','confirmed','checked_in') ORDER BY p.paid_at");
$pendingCount = (int) db_value("SELECT COUNT(*) FROM bookings WHERE status = 'pending'");
$upcoming = db_all("SELECT b.*, a.name AS accommodation_name, a.type FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
                    WHERE b.check_in > ? AND b.check_in <= DATE_ADD(?, INTERVAL 7 DAY) AND b.status IN ('pending','confirmed')
                    ORDER BY b.check_in, a.sort_order LIMIT 15", [$today, $today]);

// Occupancy this month = booked unit-days / available unit-days
$monthStart = date('Y-m-01');
$monthEnd = date('Y-m-d', strtotime($monthStart . ' +1 month'));
$daysInMonth = (int) date('t');
$unitCount = max(1, (int) db_value('SELECT COUNT(*) FROM accommodations WHERE is_active = 1'));
$bookedDays = (int) db_value("SELECT COALESCE(SUM(DATEDIFF(LEAST(check_out, ?), GREATEST(check_in, ?))),0) FROM bookings
                              WHERE status IN ('confirmed','checked_in','completed') AND check_in < ? AND check_out > ?",
    [$monthEnd, $monthStart, $monthEnd, $monthStart]);
$occupancy = round($bookedDays / ($unitCount * $daysInMonth) * 100, 1);
$incomeMonth = (float) db_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'verified' AND paid_at >= ? AND paid_at < ?", [$monthStart, $monthEnd]);

$chartLabels = $chartData = [];
if (is_admin()) {
    for ($i = 5; $i >= 0; $i--) {
        $m = date('Y-m-01', strtotime("first day of -$i month"));
        $chartLabels[] = date('M', strtotime($m));
        $chartData[] = (float) db_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='verified' AND paid_at >= ? AND paid_at < DATE_ADD(?, INTERVAL 1 MONTH)", [$m, $m]);
    }
}

$pageTitle = 'Dashboard';
$activeAdmin = 'dashboard';
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div><h1>Good <?= (int) date('G') < 12 ? 'morning' : ((int) date('G') < 18 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', current_user()['name'])[0]) ?></h1>
    <p class="text-muted mb-0"><?= date('l, F j, Y') ?></p></div>
  <div class="d-flex gap-2">
    <a href="walkin.php" class="btn btn-primary"><i class="bi bi-person-plus"></i> Walk-in</a>
    <a href="calendar.php" class="btn btn-outline-primary"><i class="bi bi-calendar3"></i> Calendar</a>
  </div>
</div>

<?php $receiptCount = count($toVerify); ?>
<div class="spec-strip" style="--cells: <?= is_admin() ? 6 : 5 ?>" aria-label="Today at a glance">
  <div class="spec-cell"><span class="spec-value"><?= count($arrivals) ?></span><span class="spec-label">Arrivals today</span></div>
  <div class="spec-cell"><span class="spec-value"><?= count($departures) ?></span><span class="spec-label">Departures today</span></div>
  <a class="spec-cell" href="bookings.php?status=pending"><span class="spec-value"><?= $pendingCount ?></span><span class="spec-label">Pending bookings</span></a>
  <a class="spec-cell<?= $receiptCount ? ' is-act' : '' ?>" href="bookings.php?view=payments"><span class="spec-value"><?= $receiptCount ?></span><span class="spec-label"><?= $receiptCount === 1 ? 'Receipt' : 'Receipts' ?> to check<?php if ($receiptCount): ?> <i class="bi bi-arrow-right" aria-hidden="true"></i><?php endif; ?></span></a>
  <div class="spec-cell"><span class="spec-value"><?= $occupancy ?><span class="unit">%</span></span><span class="spec-label">Occupancy, <?= date('F') ?></span></div>
  <?php if (is_admin()): ?>
    <div class="spec-cell"><span class="spec-value"><?= '₱' . number_format($incomeMonth) ?></span><span class="spec-label">Income, <?= date('F') ?></span></div>
  <?php endif; ?>
</div>

<div class="row g-4">
  <div class="col-xl-8">
    <div class="card p-3 mb-4">
      <h5 class="mb-3">Receipts waiting to be checked <span class="count"><?= $receiptCount ?></span></h5>
      <?php if ($toVerify): ?>
        <div class="table-responsive"><table class="table table-sm table-hover table-stack mb-0">
          <thead><tr><th>Booking</th><th>Guest</th><th>Visit</th><th class="text-end">Amount</th><th>Sent</th><th></th></tr></thead>
          <tbody><?php foreach ($toVerify as $p): ?>
            <tr>
              <td><a href="booking.php?id=<?= (int) $p['booking_id'] ?>"><?= e($p['booking_code']) ?></a></td>
              <td><?= e($p['guest_name']) ?></td>
              <td><?= e(fmt_date($p['check_in'], 'M j')) ?></td>
              <td class="text-end"><?= money($p['amount']) ?> <span class="text-muted">&middot; <?= e(payment_method_label($p['method'])) ?></span></td>
              <td class="text-muted"><?= e(fmt_date($p['paid_at'], 'M j, g:i A')) ?></td>
              <td class="text-end"><a class="btn btn-sm btn-act" href="booking.php?id=<?= (int) $p['booking_id'] ?>#payments">Review</a></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php else: ?><p class="text-muted small mb-0">All caught up. No receipts to check.</p><?php endif; ?>
    </div>

    <div class="card p-3 mb-4">
      <h5 class="mb-3">Today's arrivals <span class="count"><?= count($arrivals) ?></span></h5>
      <?php if ($arrivals): ?>
        <div class="table-responsive"><table class="table table-sm table-stack mb-0">
          <thead><tr><th>Booking</th><th>Guest</th><th>Unit</th><th>Pax</th><th>Status</th><th class="text-end">Balance</th><th></th></tr></thead>
          <tbody><?php foreach ($arrivals as $b): ?>
            <tr>
              <td><a href="booking.php?id=<?= (int) $b['id'] ?>"><?= e($b['booking_code']) ?></a></td>
              <td><?= e($b['guest_name']) ?><br><span class="small text-muted"><?= e($b['guest_phone']) ?></span></td>
              <td><?= e($b['accommodation_name']) ?></td>
              <td><?= (int) $b['num_adults'] + (int) $b['num_kids'] ?></td>
              <td><?= status_badge($b['status']) ?></td>
              <td class="text-end"><?= money(balance_due($b)) ?></td>
              <td><?php if ($b['status'] === 'confirmed'): ?>
                <form method="post" action="booking.php?id=<?= (int) $b['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="check_in"><button class="btn btn-sm btn-outline-primary">Check in</button></form>
              <?php endif; ?></td>
            </tr>
          <?php endforeach; ?></tbody>
        </table></div>
      <?php else: ?><p class="text-muted small mb-0">No arrivals today.</p><?php endif; ?>
    </div>

    <?php if ($departures): ?>
      <div class="card p-3 mb-4">
        <h5 class="mb-3">Checking out today <span class="count"><?= count($departures) ?></span></h5>
        <div class="table-responsive"><table class="table table-sm table-stack mb-0"><tbody><?php foreach ($departures as $b): ?>
          <tr><td><a href="booking.php?id=<?= (int) $b['id'] ?>"><?= e($b['booking_code']) ?></a></td><td><?= e($b['guest_name']) ?></td><td><?= e($b['accommodation_name']) ?></td>
            <td class="text-end"><?= balance_due($b) > 0 ? '<span class="text-danger">Balance ' . money(balance_due($b)) . '</span>' : '<span class="text-success">Fully paid</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="booking.php?id=<?= (int) $b['id'] ?>#checkout">Check out</a></td></tr>
        <?php endforeach; ?></tbody></table></div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-xl-4">
    <?php if (is_admin()): ?>
      <div class="card p-3 mb-4">
        <h5 class="mb-3">Income, last 6 months</h5>
        <canvas id="incomeChart" height="180"></canvas>
      </div>
    <?php endif; ?>
    <div class="card p-3 mb-4">
      <h5 class="mb-3">Next 7 days <span class="count"><?= count($upcoming) ?></span></h5>
      <?php if ($upcoming): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($upcoming as $b): ?>
            <li class="list-group-item px-0 d-flex justify-content-between align-items-center">
              <div class="small"><strong><?= e(fmt_date($b['check_in'], 'D, M j')) ?></strong> &middot; <?= e($b['accommodation_name']) ?><br>
                <a href="booking.php?id=<?= (int) $b['id'] ?>"><?= e($b['booking_code']) ?></a> &middot; <?= e($b['guest_name']) ?></div>
              <?= status_badge($b['status']) ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?><p class="text-muted small mb-0">No bookings in the next 7 days.</p><?php endif; ?>
    </div>
  </div>
</div>
<?php
if (is_admin()) {
    $labels = json_encode($chartLabels);
    $data = json_encode($chartData);
    $extraScripts = <<<HTML
<script src="../assets/vendor/chartjs/chart.umd.js"></script>
<script>
const data = $data;
Chart.defaults.font.family = 'Figtree, system-ui, sans-serif';
Chart.defaults.font.size = 13;
Chart.defaults.color = '#596158';
new Chart(document.getElementById('incomeChart'), {
  type: 'bar',
  data: { labels: $labels, datasets: [{ label: 'Income', data: data, backgroundColor: data.map((v, i) => i === data.length - 1 ? '#3D583C' : '#C5CFC0'), borderRadius: 2, maxBarThickness: 44 }] },
  options: { plugins: { legend: { display: false }, tooltip: { backgroundColor: '#121812', cornerRadius: 4, callbacks: { label: c => '₱' + c.parsed.y.toLocaleString() } } },
             scales: { y: { beginAtZero: true, border: { display: false }, grid: { color: '#D6DBD3' }, ticks: { maxTicksLimit: 5, callback: v => '₱' + v.toLocaleString() } }, x: { border: { display: false }, grid: { display: false } } } }
});
</script>
HTML;
}
include __DIR__ . '/../includes/admin_footer.php';

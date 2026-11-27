<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$from = valid_date(get('from')) ? get('from') : date('Y-01-01');
$to = valid_date(get('to')) ? get('to') : date('Y-12-31');
if ($to < $from) [$from, $to] = [$to, $from];
$toExcl = date('Y-m-d', strtotime("$to +1 day"));
$active = "('confirmed','checked_in','completed')";

// --- Money: verified payments received in the period (matches the payments table exactly)
$income = (float) db_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='verified' AND paid_at >= ? AND paid_at < ?", [$from, $toExcl]);
$byMethod = db_all("SELECT method, COUNT(*) n, SUM(amount) total FROM payments WHERE status='verified' AND paid_at >= ? AND paid_at < ? GROUP BY method ORDER BY total DESC", [$from, $toExcl]);

// --- Bookings with a stay starting in the period
$statusCounts = [];
foreach (db_all('SELECT status, COUNT(*) n FROM bookings WHERE check_in >= ? AND check_in < ? GROUP BY status', [$from, $toExcl]) as $r) $statusCounts[$r['status']] = (int) $r['n'];
$totalBookings = array_sum($statusCounts);
$realised = ($statusCounts['confirmed'] ?? 0) + ($statusCounts['checked_in'] ?? 0) + ($statusCounts['completed'] ?? 0);
$lost = ($statusCounts['cancelled'] ?? 0) + ($statusCounts['expired'] ?? 0) + ($statusCounts['rejected'] ?? 0);
$bookedValue = (float) db_value("SELECT COALESCE(SUM(total_amount),0) FROM bookings WHERE status IN $active AND check_in >= ? AND check_in < ?", [$from, $toExcl]);
$avgValue = $realised ? $bookedValue / $realised : 0;
$sources = db_all("SELECT source, COUNT(*) n FROM bookings WHERE status IN $active AND check_in >= ? AND check_in < ? GROUP BY source", [$from, $toExcl]);

// --- Per month: bookings, income, occupancy
$unitCount = max(1, (int) db_value('SELECT COUNT(*) FROM accommodations WHERE is_active = 1'));
$months = [];
for ($m = date('Y-m-01', strtotime($from)); $m < $toExcl; $m = date('Y-m-01', strtotime("$m +1 month"))) {
    $mEnd = date('Y-m-01', strtotime("$m +1 month"));
    $start = max($m, $from);
    $end = min($mEnd, $toExcl);
    $daysInRange = max(1, (strtotime($end) - strtotime($start)) / 86400);
    $bookedDays = (int) db_value("SELECT COALESCE(SUM(DATEDIFF(LEAST(check_out, ?), GREATEST(check_in, ?))),0) FROM bookings
                                  WHERE status IN $active AND check_in < ? AND check_out > ?", [$end, $start, $end, $start]);
    $months[] = [
        'label'     => date('M Y', strtotime($m)),
        'bookings'  => (int) db_value("SELECT COUNT(*) FROM bookings WHERE status IN $active AND check_in >= ? AND check_in < ?", [$start, $end]),
        'income'    => (float) db_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='verified' AND paid_at >= ? AND paid_at < ?", [$start, $end]),
        'occupancy' => round($bookedDays / ($unitCount * $daysInRange) * 100, 1),
    ];
}
$peak = $months ? array_reduce($months, fn($c, $m) => ($c === null || $m['bookings'] > $c['bookings']) ? $m : $c) : null;

// --- Per accommodation
$byUnit = db_all("SELECT a.name, a.type, COUNT(b.id) n, COALESCE(SUM(DATEDIFF(b.check_out, b.check_in)),0) days, COALESCE(SUM(b.total_amount),0) value
                  FROM accommodations a
                  LEFT JOIN bookings b ON b.accommodation_id = a.id AND b.status IN $active AND b.check_in >= ? AND b.check_in < ?
                  GROUP BY a.id, a.name, a.type ORDER BY value DESC", [$from, $toExcl]);

// --- Day of week (for day cottages this shows weekend vs weekday demand)
$dow = array_fill(1, 7, 0);
foreach (db_all("SELECT DAYOFWEEK(check_in) d, COUNT(*) n FROM bookings WHERE status IN $active AND check_in >= ? AND check_in < ? GROUP BY d", [$from, $toExcl]) as $r) $dow[(int) $r['d']] = (int) $r['n'];

// --- Top add-ons / food
$topExtras = db_all("SELECT bi.description, bi.item_type, SUM(bi.quantity) qty, SUM(bi.amount) total
                     FROM booking_items bi JOIN bookings b ON b.id = bi.booking_id
                     WHERE bi.item_type IN ('addon','food') AND b.status IN $active AND b.check_in >= ? AND b.check_in < ?
                     GROUP BY bi.description, bi.item_type ORDER BY total DESC LIMIT 8", [$from, $toExcl]);

$pageTitle = 'Reports';
$activeAdmin = 'reports';
include __DIR__ . '/../includes/admin_header.php';
$qs = http_build_query(['from' => $from, 'to' => $to]);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1>Reports</h1>
  <div class="d-flex flex-wrap gap-2 no-print">
    <a class="btn btn-outline-secondary btn-sm" href="export.php?type=payments&<?= e($qs) ?>"><i class="bi bi-download"></i> Payments CSV</a>
    <a class="btn btn-outline-secondary btn-sm" href="export.php?type=bookings&<?= e($qs) ?>"><i class="bi bi-download"></i> Bookings CSV</a>
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
  </div>
</div>

<form class="card p-3 mb-4 no-print" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
    <div class="col-md-2 d-grid"><button class="btn btn-sm btn-primary">Apply</button></div>
    <div class="col-md-4 small">
      <a href="?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-t') ?>">This month</a> &middot;
      <a href="?from=<?= date('Y-m-01', strtotime('first day of -1 month')) ?>&to=<?= date('Y-m-t', strtotime('last day of -1 month')) ?>">Last month</a> &middot;
      <a href="?from=<?= date('Y-01-01') ?>&to=<?= date('Y-12-31') ?>">This year</a> &middot;
      <a href="?from=<?= date('Y-m-d', strtotime('-12 months')) ?>&to=<?= date('Y-m-d') ?>">Last 12 months</a>
    </div>
  </div>
</form>
<p class="text-muted small"><?= e(fmt_date($from)) ?> to <?= e(fmt_date($to)) ?>. Income = verified payments received in this period. Booking figures count stays starting in this period.</p>

<div class="spec-strip" style="--cells: 4" aria-label="Totals for this period">
  <div class="spec-cell"><span class="spec-value"><?= '₱' . number_format($income) ?></span><span class="spec-label">Income received</span></div>
  <div class="spec-cell"><span class="spec-value"><?= $realised ?></span><span class="spec-label">Bookings realised</span><span class="spec-note"><?= $totalBookings ?> total &middot; <?= $lost ?> cancelled or expired</span></div>
  <div class="spec-cell"><span class="spec-value"><?= '₱' . number_format($avgValue) ?></span><span class="spec-label">Average booking</span></div>
  <div class="spec-cell"><span class="spec-value"><?= $peak && $peak['bookings'] ? e($peak['label']) : '—' ?></span><span class="spec-label">Busiest month</span><span class="spec-note"><?= $peak ? (int) $peak['bookings'] . ' bookings' : '' ?></span></div>
</div>

<div class="row g-4 mb-4">
  <div class="col-xl-6"><div class="card p-3 h-100"><h5>Income and bookings per month</h5><canvas id="monthChart" height="170"></canvas></div></div>
  <div class="col-xl-6"><div class="card p-3 h-100"><h5>Occupancy per month</h5><canvas id="occChart" height="170"></canvas><p class="small text-muted mt-2 mb-0">Booked unit-days &divide; (<?= $unitCount ?> active units &times; days)</p></div></div>
</div>

<div class="row g-4 mb-4">
  <div class="col-xl-7">
    <div class="card p-3 h-100">
      <h5>By accommodation</h5>
      <div class="table-responsive"><table class="table table-sm mb-0">
        <thead><tr><th>Unit</th><th class="text-end">Bookings</th><th class="text-end">Days / nights</th><th class="text-end">Booked value</th></tr></thead>
        <tbody><?php foreach ($byUnit as $u): ?>
          <tr><td><?= e($u['name']) ?></td><td class="text-end"><?= (int) $u['n'] ?></td><td class="text-end"><?= (int) $u['days'] ?></td><td class="text-end"><?= money($u['value']) ?></td></tr>
        <?php endforeach; ?></tbody>
        <tfoot><tr><th>Total</th><th class="text-end"><?= array_sum(array_column($byUnit, 'n')) ?></th><th class="text-end"><?= array_sum(array_column($byUnit, 'days')) ?></th><th class="text-end"><?= money(array_sum(array_column($byUnit, 'value'))) ?></th></tr></tfoot>
      </table></div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card p-3 mb-4">
      <h5>Payments by method</h5>
      <table class="table table-sm mb-0"><tbody>
        <?php foreach ($byMethod as $m): ?><tr><td><?= e(payment_method_label($m['method'])) ?></td><td class="text-end"><?= (int) $m['n'] ?> payments</td><td class="text-end"><?= money($m['total']) ?></td></tr><?php endforeach; ?>
        <?php if (!$byMethod): ?><tr><td class="text-muted">No payments in this period.</td></tr><?php endif; ?>
      </tbody><tfoot><tr><th>Total</th><th></th><th class="text-end"><?= money($income) ?></th></tr></tfoot></table>
    </div>
    <div class="card p-3 mb-4">
      <h5>Busiest days of the week</h5>
      <canvas id="dowChart" height="150"></canvas>
    </div>
    <div class="card p-3">
      <h5>Online vs walk-in</h5>
      <?php foreach ($sources as $s): ?><div class="summary-line"><span><?= $s['source'] === 'walk_in' ? 'Walk-in / phone' : 'Online' ?></span><strong><?= (int) $s['n'] ?></strong></div><?php endforeach; ?>
      <?php if ($topExtras): ?>
        <h6 class="mt-3">Top extras &amp; food pre-orders</h6>
        <?php foreach ($topExtras as $x): ?><div class="summary-line"><span><?= e($x['description']) ?> &times; <?= (int) $x['qty'] ?></span><span><?= money($x['total']) ?></span></div><?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php
$labels = json_encode(array_column($months, 'label'));
$inc = json_encode(array_column($months, 'income'));
$bk = json_encode(array_column($months, 'bookings'));
$occ = json_encode(array_column($months, 'occupancy'));
$dowData = json_encode(array_values([$dow[2], $dow[3], $dow[4], $dow[5], $dow[6], $dow[7], $dow[1]]));
$extraScripts = <<<HTML
<script src="../assets/vendor/chartjs/chart.umd.js"></script>
<script>
Chart.defaults.font.family = 'Figtree, system-ui, sans-serif';
Chart.defaults.font.size = 13;
Chart.defaults.color = '#596158';
Chart.defaults.scale.grid.color = '#D6DBD3';
const green = '#3D583C', ink = '#121812';
const peso = v => '₱' + Number(v).toLocaleString();
// The period is stated above the charts, so the axis shows the month only ("Jan")
const fullLabels = $labels, shortLabels = fullLabels.map(l => l.split(' ')[0]);
// The year appears under the first month and under every January, so any range reads unambiguously
const monthTick = (v, i) => {
  const [mon, year] = fullLabels[i].split(' ');
  return i === 0 || mon === 'Jan' ? [mon, year] : mon;
};
const monthAxis = { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: false, callback: monthTick } };
const titleFull = { title: items => fullLabels[items[0].dataIndex] };
new Chart(document.getElementById('monthChart'), {
  data: { labels: shortLabels, datasets: [
    { type: 'bar', label: 'Income', data: $inc, backgroundColor: green, borderRadius: 2, yAxisID: 'y' },
    { type: 'line', label: 'Bookings', data: $bk, borderColor: ink, backgroundColor: ink, tension: 0, pointRadius: 3, yAxisID: 'y1' }
  ] },
  options: { interaction: { mode: 'index', intersect: false },
    plugins: { tooltip: { callbacks: { ...titleFull, label: c => c.dataset.label + ': ' + (c.dataset.yAxisID === 'y' ? peso(c.parsed.y) : c.parsed.y) } } },
    scales: { y: { beginAtZero: true, ticks: { callback: peso } }, y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { precision: 0 } }, x: monthAxis } }
});
const occ = $occ, occTop = Math.max(1, Math.ceil(Math.max(...occ) * 1.25));
new Chart(document.getElementById('occChart'), {
  type: 'bar', data: { labels: shortLabels, datasets: [{ label: 'Occupancy', data: occ, backgroundColor: green, borderRadius: 2 }] },
  options: { plugins: { legend: { display: false }, tooltip: { callbacks: { ...titleFull, label: c => c.parsed.y + '%' } } },
             scales: { y: { beginAtZero: true, suggestedMax: occTop, ticks: { maxTicksLimit: 5, callback: v => v + '%' } }, x: monthAxis } }
});
new Chart(document.getElementById('dowChart'), {
  type: 'bar', data: { labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], datasets: [{ label: 'Bookings', data: $dowData, backgroundColor: green, borderRadius: 2 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } } }
});
</script>
HTML;
include __DIR__ . '/../includes/admin_footer.php';

<?php
require __DIR__ . '/../includes/bootstrap.php';
require_staff();

// Availability grid: every unit x 14 days from ?start (default today; "Go to date" jumps anywhere)
$start = valid_date((string) get('start')) ? get('start') : date('Y-m-d');
$days = [];
for ($i = 0; $i < 14; $i++) $days[] = date('Y-m-d', strtotime("$start +$i day"));
$end = date('Y-m-d', strtotime("$start +14 day"));
$rows = db_all("SELECT id, booking_code, accommodation_id, guest_name, check_in, check_out, status FROM bookings
                WHERE status IN ('pending','confirmed','checked_in','completed') AND check_in < ? AND check_out > ?", [$end, $start]);
// Open units, plus hidden or archived ones that still have bookings in this window
$bookedIds = array_unique(array_map('intval', array_column($rows, 'accommodation_id')));
$units = db_all('SELECT * FROM accommodations WHERE is_active = 1' . ($bookedIds ? ' OR id IN (' . implode(',', $bookedIds) . ')' : '') . ' ORDER BY type, sort_order, name');
$grid = [];
foreach ($rows as $r) {
    foreach ($days as $d) {
        if ($r['check_in'] <= $d && $r['check_out'] > $d) $grid[$r['accommodation_id']][$d] = $r;
    }
}
$colors = ['pending' => '#9A5B00', 'confirmed' => '#3D583C', 'checked_in' => '#1E2B1D', 'completed' => '#6B7468'];

$pageTitle = 'Calendar';
$activeAdmin = 'calendar';
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1>Calendar</h1>
  <div class="small">
    <?php foreach ($colors as $s => $c): ?><span class="me-3"><span class="legend-dot" style="background:<?= $c ?>"></span><?= e(BOOKING_STATUSES[$s][0]) ?></span><?php endforeach; ?>
  </div>
</div>

<div class="card p-3 mb-4">
  <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
    <h5 class="mb-0">Unit availability <span class="small text-muted fw-normal"><?= e(fmt_date($start, 'M j')) ?> &ndash; <?= e(fmt_date($days[13], 'M j, Y')) ?></span></h5>
    <div class="d-flex flex-wrap align-items-center gap-2">
      <form class="d-flex align-items-center gap-2" method="get" role="search" aria-label="Go to date">
        <label class="small text-muted mb-0" for="goDate">Go to</label>
        <input type="date" id="goDate" name="start" class="form-control form-control-sm" value="<?= e($start) ?>" style="width:auto">
        <button class="btn btn-sm btn-primary">Go</button>
      </form>
      <div class="btn-group btn-group-sm">
        <a class="btn btn-outline-primary" href="?start=<?= date('Y-m-d', strtotime("$start -14 day")) ?>">&laquo; Previous</a>
        <a class="btn btn-outline-primary" href="?start=<?= date('Y-m-d') ?>">Today</a>
        <a class="btn btn-outline-primary" href="?start=<?= $end ?>">Next &raquo;</a>
      </div>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-bordered table-sm small mb-0 text-center" style="min-width:900px">
      <thead><tr><th class="text-start" style="width:180px">Unit</th>
        <?php foreach ($days as $d): ?><th class="<?= $d === date('Y-m-d') ? 'bg-warning-subtle' : '' ?>"><?= date('D', strtotime($d)) ?><br><?= date('M j', strtotime($d)) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($units as $u): ?>
          <tr><td class="text-start"><?= e($u['name']) ?><?php if (!$u['is_active']): ?> <span class="badge text-bg-secondary"><?= $u['archived_at'] ? 'Archived' : 'Hidden' ?></span><?php endif; ?></td>
            <?php foreach ($days as $d): $bk = $grid[$u['id']][$d] ?? null; ?>
              <?php if ($bk): ?>
                <td style="background:<?= $colors[$bk['status']] ?>;padding:0"><a href="booking.php?id=<?= (int) $bk['id'] ?>" class="d-block text-white text-decoration-none py-1" title="<?= e($bk['booking_code'] . ' - ' . $bk['guest_name']) ?>"><?= e(mb_substr($bk['guest_name'], 0, 6)) ?></a></td>
              <?php else: ?>
                <td><?php if ($d >= date('Y-m-d') && $u['is_active']): ?><a href="walkin.php?accommodation_id=<?= (int) $u['id'] ?>&check_in=<?= $d ?>" class="text-muted text-decoration-none" title="Book this slot">+</a><?php endif; ?></td>
              <?php endif; ?>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small text-muted mt-2 mb-0">Click a coloured cell to open the booking, or + to create a walk-in for that unit and date.</p>
</div>

<div id="calendar"></div>
<?php
$eventsUrl = json_encode(url('admin/calendar_events.php'));
$startJson = json_encode($start);
$extraScripts = <<<HTML
<script src="../assets/vendor/fullcalendar/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const cal = new FullCalendar.Calendar(document.getElementById('calendar'), {
    initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
    initialDate: $startJson, // the month view follows "Go to date" too
    headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listWeek' },
    events: $eventsUrl,
    eventDisplay: 'block',
    dayMaxEvents: 4,
    height: 'auto'
  });
  cal.render();
});
</script>
HTML;
include __DIR__ . '/../includes/admin_footer.php';

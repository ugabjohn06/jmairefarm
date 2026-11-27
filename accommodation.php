<?php
require __DIR__ . '/includes/bootstrap.php';

$a = db_one('SELECT * FROM accommodations WHERE id = ? AND is_active = 1', [(int) get('id')]);
if (!$a) {
    flash('warning', 'That accommodation is not available.');
    redirect('accommodations.php');
}
$pageTitle = $a['name'];
$activeNav = 'stay';
$blocked = blocked_dates((int) $a['id']);
$isNight = $a['type'] === 'overnight_room';

include __DIR__ . '/includes/header.php';
?>
<div class="container py-4 py-lg-5">
  <nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= e(url('accommodations.php')) ?>">Cottages &amp; rooms</a></li>
    <li class="breadcrumb-item active" aria-current="page"><?= e($a['name']) ?></li>
  </ol></nav>
  <div class="row g-4 g-lg-5">
    <div class="col-lg-7">
      <div class="unit-hero"><img src="<?= e(accommodation_photo($a['photo_path'], $a['type'])) ?>" alt="<?= e($a['name']) ?>"></div>
      <span class="badge text-bg-light mb-2"><i class="bi bi-<?= $isNight ? 'moon-stars' : 'sun' ?> me-1"></i><?= e(type_label($a['type'])) ?></span>
      <h1 class="mb-3"><?= e($a['name']) ?></h1>
      <?php if ($a['description']): ?><p class="lead"><?= e($a['description']) ?></p><?php endif; ?>
      <ul class="spec-list">
        <li><i class="bi bi-people"></i><span>Up to <strong><?= (int) $a['capacity'] ?></strong> guests</span></li>
        <li><i class="bi bi-cash-coin"></i><span><strong class="tnum"><?= money($a['base_rate']) ?></strong> per <?= $isNight ? 'night' : 'day' ?></span></li>
        <?php if ($isNight): ?>
          <li><i class="bi bi-clock"></i><span>Check-in <strong><?= e(setting('checkin_time')) ?></strong> &middot; Check-out <strong><?= e(setting('checkout_time')) ?></strong></span></li>
        <?php else: ?>
          <li><i class="bi bi-clock"></i><span>Day use, <strong><?= e(setting('day_hours')) ?></strong></span></li>
          <li><i class="bi bi-ticket-perforated"></i><span>Entrance per guest: adults <strong class="tnum"><?= money(setting('entrance_adult')) ?></strong>, kids <strong class="tnum"><?= money(setting('entrance_kid')) ?></strong></span></li>
        <?php endif; ?>
        <?php if ($a['inclusions']): ?><li><i class="bi bi-check2-circle"></i><span>Includes: <?= e($a['inclusions']) ?></span></li><?php endif; ?>
      </ul>
    </div>
    <div class="col-lg-5">
      <div class="side-panel">
        <h5 class="mb-1">Check the dates</h5>
        <p class="small text-muted mb-3"><span class="legend-dot" style="background:#b23b2b"></span>Crossed-out dates are taken.
          <?= $isNight ? 'Pick your check-in, then your check-out.' : 'Pick the day of your visit.' ?></p>
        <div class="inline-cal"><input type="text" id="availCal" class="d-none"></div>
        <form action="<?= e(url('book.php')) ?>" method="get" class="mt-3 pt-3 border-top">
          <input type="hidden" name="accommodation_id" value="<?= (int) $a['id'] ?>">
          <input type="hidden" name="check_in" id="dIn">
          <input type="hidden" name="check_out" id="dOut">
          <p class="small mb-3" id="pickedText" aria-live="polite">No date selected yet.</p>
          <button class="btn btn-sun btn-lg w-100" id="bookBtn" disabled>Book this <?= $isNight ? 'room' : 'cottage' ?></button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php
$blockedJson = json_encode($blocked);
$mode = $isNight ? 'range' : 'single';
$maxDays = (int) setting('max_days_ahead', 180);
$extraScripts = <<<HTML
<script>
const blocked = $blockedJson;
const isNight = '$mode' === 'range';
flatpickr('#availCal', {
  inline: true, mode: '$mode', minDate: 'today', maxDate: new Date().fp_incr($maxDays), dateFormat: 'Y-m-d',
  disable: isNight ? [] : blocked,
  onDayCreate: function (d, s, fp, dayElem) {
    // For rooms, a blocked NIGHT can still be someone's check-out day, so we only cross it out
    if (isNight && blocked.includes(fp.formatDate(dayElem.dateObj, 'Y-m-d'))) dayElem.classList.add('flatpickr-disabled');
  },
  onChange: function (dates, s, fp) {
    const fmt = d => fp.formatDate(d, 'Y-m-d');
    const nice = d => fp.formatDate(d, 'M j, Y');
    const btn = document.getElementById('bookBtn'), txt = document.getElementById('pickedText');
    document.getElementById('dIn').value = dates[0] ? fmt(dates[0]) : '';
    document.getElementById('dOut').value = dates[1] ? fmt(dates[1]) : '';
    btn.disabled = true;
    if (!isNight && dates[0]) { txt.innerHTML = 'Visit on <strong>' + nice(dates[0]) + '</strong>'; btn.disabled = false; }
    if (isNight && dates.length === 1) txt.textContent = 'Now pick your check-out date.';
    if (isNight && dates.length === 2) {
      // every night from check-in up to (not including) check-out must be free
      let ok = true; const d = new Date(dates[0]);
      while (d < dates[1]) { if (blocked.includes(fmt(d))) { ok = false; break; } d.setDate(d.getDate() + 1); }
      if (ok) { txt.innerHTML = '<strong>' + nice(dates[0]) + '</strong> to <strong>' + nice(dates[1]) + '</strong>'; btn.disabled = false; }
      else { txt.innerHTML = '<span class="text-danger">Some nights in that range are already booked. Please pick other dates.</span>'; }
    }
  }
});
</script>
HTML;
include __DIR__ . '/includes/footer.php';

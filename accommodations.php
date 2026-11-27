<?php
require __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Cottages & rooms';
$activeNav = 'stay';

$type = in_array(get('type'), ['day_cottage', 'overnight_room'], true) ? get('type') : '';
$checkIn = valid_date(get('check_in')) ? get('check_in') : '';
$checkOut = valid_date(get('check_out')) ? get('check_out') : '';
$guests = max(0, (int) get('guests'));

// For day trips the "check-out" is simply the next day
if ($checkIn && ($type === 'day_cottage' || !$checkOut || $checkOut <= $checkIn)) {
    $checkOut = $type === 'overnight_room' && $checkOut > $checkIn ? $checkOut : date('Y-m-d', strtotime($checkIn . ' +1 day'));
}
$searching = $checkIn !== '';
if ($searching && $checkIn < date('Y-m-d')) {
    flash('warning', 'Please choose today or a future date.');
    $searching = false;
}

$units = active_accommodations($type ?: null);
$freeIds = [];
if ($searching) {
    foreach (available_units($checkIn, $checkOut, $type ?: null) as $u) $freeIds[(int) $u['id']] = true;
}

include __DIR__ . '/includes/header.php';
?>
<section class="page-head">
  <div class="container">
    <h1>Cottages &amp; rooms</h1>
    <p>Each unit is booked on its own, so you always know exactly which cottage or room is yours. Pick a date to see what's open.</p>
  </div>
</section>
<div class="container pb-5">
  <form class="card p-3 p-md-4 mb-2" method="get" aria-label="Filter by date">
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small mb-1">Type</label>
        <select name="type" class="form-select" id="fType">
          <option value="">All</option>
          <option value="day_cottage" <?= $type === 'day_cottage' ? 'selected' : '' ?>>Day cottages</option>
          <option value="overnight_room" <?= $type === 'overnight_room' ? 'selected' : '' ?>>Overnight rooms</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">Date / check-in</label>
        <input type="date" name="check_in" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= e($checkIn) ?>">
      </div>
      <div class="col-md-2" id="fOutWrap">
        <label class="form-label small mb-1">Check-out</label>
        <input type="date" name="check_out" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= e($type === 'overnight_room' ? $checkOut : '') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Guests</label>
        <input type="number" name="guests" class="form-control" min="1" value="<?= $guests ?: '' ?>">
      </div>
      <div class="col-md-2 d-grid">
        <button class="btn btn-primary" style="height:46px"><i class="bi bi-search me-1"></i> Search</button>
      </div>
    </div>
    <?php if ($searching): ?>
      <p class="small mb-0 mt-2 text-muted">Showing availability for <strong><?= e(fmt_date($checkIn)) ?></strong><?= $type === 'overnight_room' ? ' to <strong>' . e(fmt_date($checkOut)) . '</strong>' : '' ?>. <a href="<?= e(url('accommodations.php')) ?>">Clear</a></p>
    <?php endif; ?>
  </form>

  <?php foreach (['day_cottage' => 'Day cottages', 'overnight_room' => 'Overnight rooms'] as $t => $heading):
      $list = array_filter($units, fn($u) => $u['type'] === $t);
      if (!$list) continue; ?>
    <h2 class="section-title"><?= $t === 'day_cottage' ? '<i class="bi bi-sun"></i>' : '<i class="bi bi-moon-stars"></i>' ?> <?= e($heading) ?></h2>
    <?php if ($t === 'day_cottage'): ?>
      <p class="section-note">Open <?= e(setting('day_hours')) ?>. Entrance fee per guest is added (adults <?= money(setting('entrance_adult')) ?>, kids <?= money(setting('entrance_kid')) ?>); swimming is optional.</p>
    <?php else: ?>
      <p class="section-note">Check-in <?= e(setting('checkin_time')) ?>, check-out <?= e(setting('checkout_time')) ?>. Rates include entrance, swimming and breakfast.</p>
    <?php endif; ?>
    <div class="row g-4">
      <?php foreach ($list as $a):
          $free = !$searching || isset($freeIds[(int) $a['id']]);
          $fits = !$guests || $guests <= (int) $a['capacity'];
          $bookQs = http_build_query(array_filter(['accommodation_id' => $a['id'], 'check_in' => $checkIn, 'check_out' => $t === 'overnight_room' ? $checkOut : '', 'guests' => $guests ?: ''])); ?>
        <div class="col-sm-6 col-lg-4">
          <article class="unit-card position-relative<?= (!$free || !$fits) ? ' is-unavailable' : '' ?>">
            <div class="unit-media">
              <img src="<?= e(accommodation_photo($a['photo_path'], $a['type'])) ?>" alt="" loading="lazy">
              <span class="unit-type"><i class="bi bi-<?= $t === 'overnight_room' ? 'moon-stars' : 'sun' ?>"></i> <?= e(type_label($a['type'])) ?></span>
              <?php if ($searching): ?>
                <span class="unit-state"><?= $free ? '<span class="badge text-bg-success">Available</span>' : '<span class="badge text-bg-secondary">Booked</span>' ?></span>
              <?php endif; ?>
            </div>
            <div class="unit-body">
              <h3 class="unit-name"><?= e($a['name']) ?></h3>
              <p class="unit-meta mb-2"><i class="bi bi-people me-1"></i>Up to <?= (int) $a['capacity'] ?> guests
                <?php if (!$fits): ?><span class="text-danger">&middot; too small for <?= $guests ?></span><?php endif; ?></p>
              <?php if ($a['description']): ?><p class="unit-desc"><?= e($a['description']) ?></p><?php endif; ?>
              <?php if ($a['inclusions']): ?><p class="unit-incl"><i class="bi bi-check2-circle"></i><span><?= e($a['inclusions']) ?></span></p><?php endif; ?>
              <div class="unit-foot">
                <span class="price-tag"><?= money($a['base_rate']) ?> <small>/ <?= $a['type'] === 'overnight_room' ? 'night' : 'day' ?></small></span>
                <div class="d-flex gap-2 position-relative" style="z-index:1">
                  <a href="<?= e(url('accommodation.php?id=' . $a['id'])) ?>" class="btn btn-sm btn-outline-primary">Calendar</a>
                  <?php if ($free && $fits): ?>
                    <a href="<?= e(url('book.php?' . $bookQs)) ?>" class="btn btn-sm btn-primary">Book</a>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <?php if (!$units): ?><p class="text-muted py-5 text-center">No accommodations are open for booking right now.</p><?php endif; ?>
</div>
<?php
$extraScripts = <<<HTML
<script>
  const t = document.getElementById('fType'), w = document.getElementById('fOutWrap');
  function toggleOut() { w.style.display = t.value === 'overnight_room' ? '' : 'none'; }
  t.addEventListener('change', toggleOut); toggleOut();
</script>
HTML;
include __DIR__ . '/includes/footer.php';

<?php
require __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Home';
$activeNav = 'home';
$headerOverlay = true;

$featured = db_all("SELECT * FROM accommodations WHERE is_active = 1 AND id IN (
                      SELECT MIN(id) FROM accommodations WHERE is_active = 1 GROUP BY base_rate, type, capacity)
                    ORDER BY type DESC, base_rate DESC LIMIT 6");
$activities = db_all("SELECT o.name FROM offerings o JOIN categories c ON c.id = o.category_id WHERE c.name = 'Activity' ORDER BY o.id");
$resort = setting('resort_name', "J'Maire Farm");
$holdHours = (int) setting('payment_deadline_hours', 24);
$downPct = (float) setting('downpayment_percent', 50);
// The map searches the resort's name and address, which lands on its Google Maps place;
// "Get directions" opens the owner's Maps link, or a search for the same text when none is set.
$mapQuery = $resort . ', ' . setting('address');
$mapsUrl = setting('maps_url') ?: 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($mapQuery);
$phone = setting('contact_phone');
$email = setting('contact_email');

include __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <div class="hero-media" aria-hidden="true">
    <img src="<?= e(url('assets/img/hero-farm.jpg')) ?>" alt="" width="1920" height="1440" fetchpriority="high">
  </div>
  <div class="container">
    <div class="hero-copy">
      <h1><span>An Extraordinary Stay.</span> <span>A Timeless Experience.</span></h1>
      <p class="lead">Thoughtfully designed spaces, peaceful surroundings, and moments worth remembering.</p>
      <div class="hero-links">
        <a href="<?= e(url('accommodations.php')) ?>" class="btn btn-light btn-lg">See cottages &amp; rooms</a>
        <a href="<?= e(url('menu.php')) ?>" class="text-link">Food &amp; activities</a>
      </div>
    </div>

    <form class="booking-bar" action="<?= e(url('accommodations.php')) ?>" method="get" id="quickSearch" aria-label="Check availability">
      <div class="bb-grid">
        <div>
          <span class="form-label d-block">Visit type</span>
          <div class="segmented" role="radiogroup" aria-label="Visit type">
            <input type="radio" name="type" id="qsDay" value="day_cottage" checked>
            <label for="qsDay"><i class="bi bi-sun"></i> Day trip</label>
            <input type="radio" name="type" id="qsNight" value="overnight_room">
            <label for="qsNight"><i class="bi bi-moon-stars"></i> Overnight</label>
          </div>
        </div>
        <div>
          <label class="form-label" for="qsDates" id="qsDateLabel">Date of visit</label>
          <input type="text" class="form-control" id="qsDates" placeholder="Pick a date" readonly>
          <input type="hidden" name="check_in" id="qsIn">
          <input type="hidden" name="check_out" id="qsOut">
        </div>
        <div>
          <label class="form-label" for="qsGuests">Guests</label>
          <input type="number" class="form-control" id="qsGuests" name="guests" min="1" max="50" value="2" inputmode="numeric">
        </div>
        <button class="btn btn-primary btn-lg"><i class="bi bi-search"></i> Check availability</button>
      </div>
    </form>
  </div>
</section>

<section class="facts" aria-label="Visit details">
  <div class="container">
    <dl class="facts-grid mb-0">
      <div class="fact">
        <dt><i class="bi bi-sun"></i> Day use</dt>
        <dd><?= e(setting('day_hours')) ?><span>Picnic cottages, pool and farm grounds</span></dd>
      </div>
      <div class="fact">
        <dt><i class="bi bi-moon-stars"></i> Overnight</dt>
        <dd>In <?= e(setting('checkin_time')) ?> &middot; Out <?= e(setting('checkout_time')) ?><span>Rooms include entrance, swimming and breakfast</span></dd>
      </div>
      <div class="fact">
        <dt><i class="bi bi-ticket-perforated"></i> Entrance</dt>
        <dd><?= money(setting('entrance_adult')) ?> adult<span>Kids <?= money(setting('entrance_kid')) ?> &middot; added to day cottages</span></dd>
      </div>
      <div class="fact">
        <dt><i class="bi bi-phone"></i> To reserve</dt>
        <dd><?= rtrim(rtrim(number_format($downPct, 2), '0'), '.') ?>% downpayment<span>By GCash or bank, held <?= $holdHours ?> hours for you</span></dd>
      </div>
    </dl>
  </div>
</section>

<section class="section section-tint">
  <div class="container">
    <div class="section-head">
      <div>
        <h2>Cottages &amp; rooms</h2>
        <p>Open-air cottages for a day of picnics and swimming, and rooms when one day isn't enough. Every unit is booked on its own, so you know exactly which one is yours.</p>
      </div>
      <a href="<?= e(url('accommodations.php')) ?>" class="btn btn-outline-primary">View all units <i class="bi bi-arrow-right ms-1"></i></a>
    </div>
    <div class="row g-4">
      <?php foreach ($featured as $a): $night = $a['type'] === 'overnight_room'; ?>
        <div class="col-md-6 col-lg-4">
          <article class="unit-card position-relative">
            <div class="unit-media">
              <img src="<?= e(accommodation_photo($a['photo_path'], $a['type'])) ?>" alt="" loading="lazy">
              <span class="unit-type"><i class="bi bi-<?= $night ? 'moon-stars' : 'sun' ?>"></i> <?= e(type_label($a['type'])) ?></span>
            </div>
            <div class="unit-body">
              <h3><a class="unit-link" href="<?= e(url('accommodation.php?id=' . $a['id'])) ?>"><?= e(preg_replace('/\s+(\d+|[A-Z])$/', '', $a['name'])) ?></a></h3>
              <p class="unit-meta"><i class="bi bi-people me-1"></i>Up to <?= (int) $a['capacity'] ?> guests<?= $a['inclusions'] ? ' &middot; ' . e($a['inclusions']) : '' ?></p>
              <div class="unit-foot">
                <span class="price-tag"><?= money($a['base_rate']) ?> <small>/ <?= $night ? 'night' : 'day' ?></small></span>
                <span class="small fw-semibold text-primary">Dates &amp; details <i class="bi bi-arrow-right"></i></span>
              </div>
            </div>
          </article>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head">
      <div>
        <h2>Booking takes three steps</h2>
        <p>You see the full price, entrance fees included, before anything is saved.</p>
      </div>
    </div>
    <ol class="steps list-unstyled mb-0">
      <li class="step-item">
        <span class="num">1</span>
        <h3>Pick a date and a unit</h3>
        <p>Taken dates are crossed out on every calendar, so there are no double bookings.</p>
      </li>
      <li class="step-item">
        <span class="num">2</span>
        <h3>Pay the <?= rtrim(rtrim(number_format($downPct, 2), '0'), '.') ?>% downpayment</h3>
        <p>Send it by GCash or bank transfer and upload a photo of the receipt. Your date is held for <?= $holdHours ?> hours while you do.</p>
      </li>
      <li class="step-item">
        <span class="num">3</span>
        <h3>Get your confirmation</h3>
        <p>Our staff checks the receipt and emails you. The balance is paid at the farm on the day.</p>
      </li>
    </ol>
  </div>
</section>

<section class="section section-tint">
  <div class="container">
    <div class="row g-4 g-lg-5 align-items-start">
      <div class="col-lg-6">
        <h2>Things to do at the farm</h2>
        <p class="text-muted mb-4" style="max-width:48ch">Add activities when you book, or pre-order food from the farm kitchen so it's ready when you arrive.</p>
        <?php if ($activities): ?>
          <ul class="activity-list mb-4">
            <?php foreach ($activities as $act): ?><li><?= e($act['name']) ?></li><?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <a href="<?= e(url('menu.php')) ?>" class="btn btn-outline-primary">See the food menu</a>
      </div>
      <div class="col-lg-5 offset-lg-1">
        <div class="ticket">
          <div class="ticket-head">
            <div>
              <div class="t-title">Entrance &amp; pool</div>
              <div class="t-sub">Per guest, per visit</div>
            </div>
            <img class="mark" src="<?= e(url('assets/img/logo-mark.png')) ?>" alt="" width="44" height="44">
          </div>
          <div class="ticket-row">
            <div class="t-label">Entrance <span>Day cottages</span></div>
            <div class="t-col"><small>Adult</small><?= money(setting('entrance_adult')) ?></div>
            <div class="t-col"><small>Kid</small><?= money(setting('entrance_kid')) ?></div>
          </div>
          <div class="ticket-row">
            <div class="t-label">Swimming <span>Optional for day trips</span></div>
            <div class="t-col"><small>Adult</small><?= money(setting('swimming_adult')) ?></div>
            <div class="t-col"><small>Kid</small><?= money(setting('swimming_kid')) ?></div>
          </div>
          <p class="ticket-note"><i class="bi bi-info-circle me-1"></i>Overnight rooms already include entrance and swimming. Kids are below 12.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section" id="find-us" aria-labelledby="findUsTitle">
  <div class="container">
    <div class="row g-4 g-lg-5 align-items-center">
      <div class="col-lg-5">
        <h2 id="findUsTitle">How to find us</h2>
        <p class="section-note">Open the map for directions from wherever you are.</p>
        <dl class="find-list">
          <div><dt>Address</dt><dd><?= e(setting('address')) ?></dd></div>
          <div><dt>Day use</dt><dd><?= e(setting('day_hours')) ?></dd></div>
          <div><dt>Overnight</dt><dd>In <?= e(setting('checkin_time')) ?> &middot; Out <?= e(setting('checkout_time')) ?></dd></div>
          <?php if ($phone): ?><div><dt>Call or text</dt><dd><a href="tel:<?= e(preg_replace('/[^\d+]/', '', $phone)) ?>"><?= e($phone) ?></a></dd></div><?php endif; ?>
          <?php if ($email): ?><div><dt>Email</dt><dd><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></dd></div><?php endif; ?>
        </dl>
        <a class="btn btn-primary btn-lg" href="<?= e($mapsUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-sign-turn-right"></i> Get directions</a>
      </div>
      <div class="col-lg-7">
        <div class="map-frame">
          <iframe src="https://maps.google.com/maps?q=<?= e(rawurlencode($mapQuery)) ?>&amp;z=15&amp;hl=en&amp;output=embed"
                  title="Map showing <?= e($resort) ?> in <?= e(setting('address')) ?>" loading="lazy"
                  referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="cta-band section">
  <div class="container">
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-4">
      <div>
        <h2>Pick your date while it's open</h2>
        <p>Book online now and we'll hold your slot for <?= $holdHours ?> hours while you send the downpayment.</p>
      </div>
      <a href="<?= e(url('book.php')) ?>" class="btn btn-light btn-lg flex-shrink-0">Book a visit <i class="bi bi-arrow-right ms-1"></i></a>
    </div>
  </div>
</section>

<?php
$extraScripts = <<<HTML
<script>
(function () {
  const inEl = document.getElementById('qsIn'), outEl = document.getElementById('qsOut');
  const dateEl = document.getElementById('qsDates');
  let fp;
  function setup(mode) {
    if (typeof flatpickr === 'undefined') { dateEl.removeAttribute('readonly'); dateEl.type = 'date'; inEl.removeAttribute('name'); dateEl.name = 'check_in'; return; }
    if (fp) fp.destroy();
    dateEl.value = ''; inEl.value = ''; outEl.value = '';
    document.getElementById('qsDateLabel').textContent = mode === 'range' ? 'Check-in and check-out' : 'Date of visit';
    fp = flatpickr(dateEl, {
      mode: mode, minDate: 'today', dateFormat: 'Y-m-d', altInput: true, altFormat: 'D, M j',
      altInputClass: 'form-control', disableMobile: true,
      onChange: function (d, s, inst) {
        inEl.value = d[0] ? inst.formatDate(d[0], 'Y-m-d') : '';
        outEl.value = d[1] ? inst.formatDate(d[1], 'Y-m-d') : '';
      }
    });
  }
  document.querySelectorAll('input[name=type]').forEach(r => r.addEventListener('change', e => setup(e.target.value === 'overnight_room' ? 'range' : 'single')));
  setup('single');
})();
</script>
HTML;
include __DIR__ . '/includes/footer.php';

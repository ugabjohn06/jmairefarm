<?php
// Shared booking form: used by book.php (guests) and admin/walkin.php (staff).
// Expects: $formMode ('guest' | 'walkin'), $prefill (array)
$formMode = $formMode ?? 'guest';
$prefill = $prefill ?? [];
$val = fn($k, $d = '') => old($k, $prefill[$k] ?? $d);
$units = active_accommodations();
$addonList = active_addons();
$foodList = food_menu();
$oldAddons = (array) old('addons', []);
$oldFood = (array) old('food', []);
?>
<form method="post" id="bookingForm" novalidate data-quote-url="<?= e(url('api/quote.php')) ?>" data-avail-url="<?= e(url('api/availability.php')) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="check_in" id="checkIn" value="<?= e($val('check_in')) ?>">
  <input type="hidden" name="check_out" id="checkOut" value="<?= e($val('check_out')) ?>">
  <div class="row g-4">
    <div class="col-lg-7">
      <div class="form-step">
        <h5 class="mb-3"><span class="badge rounded-pill text-bg-primary me-1">1</span> Where and when</h5>
        <div class="mb-3">
          <label class="form-label">Cottage or room</label>
          <select name="accommodation_id" id="accSelect" class="form-select" required>
            <option value="">Choose one...</option>
            <?php foreach (['day_cottage' => 'Day cottages (day use)', 'overnight_room' => 'Overnight rooms'] as $t => $label): ?>
              <optgroup label="<?= e($label) ?>">
                <?php foreach ($units as $u) if ($u['type'] === $t): ?>
                  <option value="<?= (int) $u['id'] ?>" data-type="<?= e($u['type']) ?>" data-capacity="<?= (int) $u['capacity'] ?>"
                    <?= (string) $val('accommodation_id') === (string) $u['id'] ? 'selected' : '' ?>>
                    <?= e($u['name']) ?> &middot; up to <?= (int) $u['capacity'] ?> pax &middot; <?= money($u['base_rate']) ?>/<?= $t === 'overnight_room' ? 'night' : 'day' ?>
                  </option>
                <?php endif; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-1">
          <label class="form-label" id="dateLabel">Date</label>
          <input type="text" id="dateInput" class="form-control" placeholder="Choose a cottage or room first" readonly disabled>
          <div class="form-text" id="dateHelp">Taken dates are crossed out.</div>
        </div>
      </div>

      <div class="form-step">
        <h5 class="mb-3"><span class="badge rounded-pill text-bg-primary me-1">2</span> Guests</h5>
        <div class="row g-3">
          <div class="col-6 col-md-4">
            <label class="form-label">Adults</label>
            <input type="number" name="num_adults" id="numAdults" class="form-control" min="1" max="50" value="<?= e($val('num_adults', 2)) ?>" required>
          </div>
          <div class="col-6 col-md-4">
            <label class="form-label">Kids <small class="text-muted">(below 12)</small></label>
            <input type="number" name="num_kids" id="numKids" class="form-control" min="0" max="50" value="<?= e($val('num_kids', 0)) ?>">
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <span class="small text-muted" id="capacityText"></span>
          </div>
          <div class="col-12" id="swimWrap">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" role="switch" name="include_swimming" id="includeSwim" value="1" <?= $val('include_swimming') ? 'checked' : '' ?>>
              <label class="form-check-label" for="includeSwim">Add swimming for everyone (adults <?= money(setting('swimming_adult')) ?>, kids <?= money(setting('swimming_kid')) ?>)</label>
            </div>
          </div>
        </div>
      </div>

      <div class="form-step">
        <h5 class="mb-1"><span class="badge rounded-pill text-bg-primary me-1">3</span> Extras <small class="text-muted fw-normal fs-6" style="font-family:var(--jm-text)">optional</small></h5>
        <p class="small text-muted">Add activities now, or pre-order food so it's ready when you arrive.</p>
        <?php if ($addonList): ?>
          <div class="row g-2 mb-3">
            <?php foreach ($addonList as $ad): ?>
              <div class="col-md-6">
                <div class="addon-tile" data-addon="<?= (int) $ad['id'] ?>">
                  <div class="small"><strong id="addonName<?= (int) $ad['id'] ?>"><?= e($ad['name']) ?></strong><br><span class="text-muted"><?= money($ad['price']) ?> <?= e($ad['unit_label']) ?></span>
                    <?php if ($ad['daily_stock'] !== null): ?><br><span class="addon-left" id="addonLeft<?= (int) $ad['id'] ?>"><?= (int) $ad['daily_stock'] ?> available per day</span><?php endif; ?></div>
                  <input type="number" class="form-control form-control-sm qty-input quote-input" name="addons[<?= (int) $ad['id'] ?>]" min="0" max="<?= $ad['daily_stock'] === null ? 50 : min(50, (int) $ad['daily_stock']) ?>" value="<?= (int) ($oldAddons[$ad['id']] ?? 0) ?>"
                         aria-labelledby="addonName<?= (int) $ad['id'] ?>"<?= $ad['daily_stock'] !== null ? ' aria-describedby="addonLeft' . (int) $ad['id'] . '"' : '' ?>>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($foodList): ?>
          <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#foodPanel">
            <i class="bi bi-egg-fried"></i> Pre-order food from the menu
          </button>
          <div class="collapse <?= array_filter(array_map('intval', $oldFood)) ? 'show' : '' ?> mt-3" id="foodPanel">
            <input type="search" class="form-control form-control-sm mb-2" id="foodSearch" placeholder="Search the menu...">
            <div class="food-list border rounded-3">
              <?php $lastCat = null; foreach ($foodList as $f): ?>
                <?php if ($lastCat !== $f['category']): $lastCat = $f['category']; ?>
                  <div class="px-2 py-1 bg-sage small fw-semibold food-cat"><?= e($f['category']) ?></div>
                <?php endif; ?>
                <div class="d-flex align-items-center justify-content-between px-2 py-1 border-bottom food-row" data-name="<?= e(strtolower($f['name'] . ' ' . $f['category'])) ?>">
                  <span class="small"><?= e($f['name']) ?><?= $f['variant_label'] !== 'default' ? ' <span class="text-muted">(' . e($f['variant_label']) . ')</span>' : '' ?> &middot; <?= money($f['price']) ?></span>
                  <input type="number" class="form-control form-control-sm qty-input quote-input" name="food[<?= (int) $f['variant_id'] ?>]" min="0" max="50" value="<?= (int) ($oldFood[$f['variant_id']] ?? 0) ?>">
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div class="form-step">
        <h5 class="mb-3"><span class="badge rounded-pill text-bg-primary me-1">4</span> <?= $formMode === 'walkin' ? 'Guest details' : 'Your details' ?></h5>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Full name</label>
            <input type="text" name="guest_name" class="form-control" maxlength="120" value="<?= e($val('guest_name')) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Mobile number</label>
            <input type="tel" name="guest_phone" class="form-control" maxlength="20" placeholder="09XX XXX XXXX" value="<?= e($val('guest_phone')) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email <?= $formMode === 'walkin' ? '<small class="text-muted">(optional)</small>' : '' ?></label>
            <input type="email" name="guest_email" class="form-control" maxlength="150" value="<?= e($val('guest_email')) ?>" <?= $formMode === 'guest' ? 'required' : '' ?>>
          </div>
          <div class="col-12">
            <label class="form-label">Special requests <small class="text-muted">(optional)</small></label>
            <textarea name="special_requests" class="form-control" rows="2" maxlength="1000" placeholder="e.g. birthday celebration, arriving around 10 AM"><?= e($val('special_requests')) ?></textarea>
          </div>
        </div>
      </div>

      <?php if ($formMode === 'walkin'): ?>
        <div class="form-step">
          <h5 class="mb-3"><span class="badge rounded-pill text-bg-primary me-1">5</span> Payment received</h5>
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Amount</label>
              <input type="number" step="0.01" min="0" name="pay_amount" id="payAmount" class="form-control" value="<?= e($val('pay_amount')) ?>">
              <div class="form-text"><a href="#" id="fillDown">Downpayment</a> &middot; <a href="#" id="fillFull">Full amount</a></div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Method</label>
              <select name="pay_method" class="form-select">
                <?php foreach (['cash' => 'Cash', 'gcash' => 'GCash', 'bank' => 'Bank transfer'] as $k => $l): ?>
                  <option value="<?= $k ?>" <?= $val('pay_method', 'cash') === $k ? 'selected' : '' ?>><?= $l ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label">Reference no. <small class="text-muted">(optional)</small></label>
              <input type="text" name="pay_reference" class="form-control" maxlength="60" value="<?= e($val('pay_reference')) ?>">
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="check_in_now" id="checkInNow" value="1" <?= $val('check_in_now', '1') ? 'checked' : '' ?>>
                <label class="form-check-label" for="checkInNow">Guest is here now: mark as <strong>checked in</strong> (only if the date is today)</label>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <div class="col-lg-5">
      <div class="card p-3 p-md-4 summary-card">
        <h5 class="mb-3">Price summary</h5>
        <div id="quoteBox" class="small text-muted">Choose a unit and date to see the price.</div>
        <div id="quoteWarn" class="alert alert-danger small mt-3 d-none mb-0"></div>
        <?php if ($formMode === 'guest'): ?>
          <hr>
          <p class="small text-muted mb-2"><i class="bi bi-info-circle"></i> Your date is held for <strong><?= (int) setting('payment_deadline_hours', 24) ?> hours</strong>. Pay the <?= (float) setting('downpayment_percent', 50) ?>% downpayment by GCash or bank transfer, then upload your receipt on the next page.</p>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="agree" id="agree" value="1" required>
            <label class="form-check-label small" for="agree">I agree to the <a href="#" data-bs-toggle="modal" data-bs-target="#policyModal">booking and cancellation policy</a>.</label>
          </div>
        <?php endif; ?>
        <button class="btn btn-<?= $formMode === 'walkin' ? 'primary' : 'sun' ?> btn-lg w-100 mt-2" id="submitBtn" type="submit">
          <?= $formMode === 'walkin' ? 'Save walk-in booking' : 'Book and continue to payment' ?>
        </button>
      </div>
    </div>
  </div>
</form>

<div class="modal fade" id="policyModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Booking &amp; cancellation policy</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body small">
      <ul>
        <li>A <?= (float) setting('downpayment_percent', 50) ?>% downpayment is required to confirm your booking.</li>
        <li>Unpaid bookings are released automatically after <?= (int) setting('payment_deadline_hours', 24) ?> hours.</li>
        <li>The remaining balance is paid at the farm on the day of your visit.</li>
        <li><?= e(setting('cancellation_policy')) ?></li>
      </ul>
    </div>
  </div></div>
</div>

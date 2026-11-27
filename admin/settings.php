<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

// key => [label, type, help]
$groups = [
    'Resort info' => [
        'resort_name'   => ['Resort name', 'text', ''],
        'tagline'       => ['Tagline', 'text', 'Shown on the home page and footer'],
        'address'       => ['Address', 'text', ''],
        'contact_phone' => ['Contact number', 'text', ''],
        'contact_email' => ['Contact email', 'email', ''],
        'facebook_url'  => ['Facebook page URL', 'url', ''],
        'maps_url'      => ['Google Maps link', 'url', 'Opened by "Get directions" on the home page. The map itself finds the farm from the resort name and address'],
    ],
    'Booking rules' => [
        'downpayment_percent'    => ['Downpayment (%)', 'number', 'Percent of the total required to confirm a booking'],
        'payment_deadline_hours' => ['Payment deadline (hours)', 'number', 'Unpaid bookings expire after this and the date is released'],
        'max_days_ahead'         => ['Booking window (days ahead)', 'number', 'How far ahead guests can book online'],
        'max_nights'             => ['Max nights per online booking', 'number', ''],
        'day_hours'              => ['Day-use hours', 'text', 'e.g. 8:00 AM - 5:00 PM'],
        'checkin_time'           => ['Overnight check-in time', 'text', ''],
        'checkout_time'          => ['Overnight check-out time', 'text', ''],
        'cancellation_policy'    => ['Cancellation policy', 'textarea', 'Shown to guests before they book'],
    ],
    'Fees (day cottages)' => [
        'entrance_adult' => ['Entrance - Adult (₱)', 'number', ''],
        'entrance_kid'   => ['Entrance - Kids (₱)', 'number', ''],
        'swimming_adult' => ['Swimming - Adult (₱)', 'number', ''],
        'swimming_kid'   => ['Swimming - Kids (₱)', 'number', ''],
    ],
    'Payment details shown to guests' => [
        'gcash_number'      => ['GCash number', 'text', ''],
        'gcash_name'        => ['GCash account name', 'text', ''],
        'bank_name'         => ['Bank', 'text', ''],
        'bank_account_name' => ['Bank account name', 'text', ''],
        'bank_account_no'   => ['Bank account number', 'text', ''],
    ],
];

if (is_post()) {
    csrf_check();
    $current = settings_all();
    $changed = [];
    foreach ($groups as $fields) {
        foreach ($fields as $key => [$label, $type]) {
            $val = post($key);
            if ($type === 'number') {
                if (!is_numeric($val) || $val < 0) {
                    flash('error', $label . ' must be a number.');
                    redirect('admin/settings.php');
                }
                if ($key === 'downpayment_percent' && $val > 100) {
                    flash('error', 'Downpayment cannot be more than 100%.');
                    redirect('admin/settings.php');
                }
            }
            if (($current[$key] ?? null) !== $val) {
                db_exec('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', [$key, $val]);
                $changed[] = $key;
            }
        }
    }
    if ($changed) audit('settings.update', 'settings', null, implode(', ', $changed));
    flash('success', $changed ? 'Settings saved.' : 'No changes.');
    redirect('admin/settings.php');
}

$s = settings_all();
$pageTitle = 'Settings';
$activeAdmin = 'settings';
include __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="mb-3">Settings</h1>
<form method="post" style="max-width:900px">
  <?= csrf_field() ?>
  <?php foreach ($groups as $title => $fields): ?>
    <div class="card p-4 mb-4">
      <h5 class="mb-3"><?= e($title) ?></h5>
      <div class="row g-3">
        <?php foreach ($fields as $key => [$label, $type, $help]): ?>
          <div class="<?= $type === 'textarea' ? 'col-12' : 'col-md-6' ?>">
            <label class="form-label small"><?= e($label) ?></label>
            <?php if ($type === 'textarea'): ?>
              <textarea name="<?= e($key) ?>" class="form-control" rows="3"><?= e($s[$key] ?? '') ?></textarea>
            <?php else: ?>
              <input type="<?= $type ?>" <?= $type === 'number' ? 'step="any" min="0"' : '' ?> name="<?= e($key) ?>" class="form-control" value="<?= e($s[$key] ?? '') ?>">
            <?php endif; ?>
            <?php if ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <button class="btn btn-primary btn-lg">Save settings</button>
</form>
<?php include __DIR__ . '/../includes/admin_footer.php';

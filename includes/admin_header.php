<?php
// Admin/staff layout. Set $pageTitle and $activeAdmin before including.
$me = current_user();
$pendingPayCount = (int) db_value("SELECT COUNT(*) FROM payments p JOIN bookings b ON b.id = p.booking_id WHERE p.status = 'pending' AND b.status IN ('pending','confirmed','checked_in')");
$pendingBookCount = (int) db_value("SELECT COUNT(*) FROM bookings WHERE status = 'pending'");
$activeAdmin = $activeAdmin ?? '';
$resort = setting('resort_name', "J'Maire Farm");
$initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($me['name'] ?? '?')), 0, 2))));
$link = function (string $key, string $href, string $icon, string $label, int $badge = 0) use ($activeAdmin) {
    return '<a class="nav-link' . ($activeAdmin === $key ? ' active' : '') . '" href="' . e(url('admin/' . $href)) . '"><i class="bi bi-' . $icon . '"></i> ' . e($label) .
        ($badge ? '<span class="badge rounded-pill text-bg-warning">' . $badge . '</span>' : '') . '</a>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle ?? 'Dashboard') ?> | <?= e(setting('resort_name', "J'Maire Farm")) ?> Admin</title>
<?php include __DIR__ . '/head_assets.php'; ?>
</head>
<body class="admin-body">
<aside class="admin-sidebar" id="sidebar">
  <a class="brand" href="<?= e(url('admin/index.php')) ?>"><?= logo_img('white', 'logo', 78) ?></a>
  <div class="nav-section">Operations</div>
  <?= $link('dashboard', 'index.php', 'speedometer2', 'Dashboard') ?>
  <?= $link('bookings', 'bookings.php', 'journal-text', 'Bookings', $pendingBookCount) ?>
  <?= $link('payments', 'bookings.php?view=payments', 'receipt', 'Payments to verify', $pendingPayCount) ?>
  <?= $link('walkin', 'walkin.php', 'person-plus', 'Walk-in booking') ?>
  <?= $link('calendar', 'calendar.php', 'calendar3', 'Calendar') ?>
  <?= $link('guests', 'guests.php', 'people', 'Guests') ?>
  <?= $link('emails', 'emails.php', 'envelope', 'Email log') ?>
  <?php if (($me['role'] ?? '') === 'admin'): ?>
    <div class="nav-section">Owner</div>
    <?= $link('reports', 'reports.php', 'bar-chart-line', 'Reports') ?>
    <?= $link('accommodations', 'accommodations.php', 'house-door', 'Accommodations') ?>
    <?= $link('addons', 'addons.php', 'plus-square', 'Add-ons') ?>
    <?= $link('menu', 'menu_list.php', 'egg-fried', 'Farm menu') ?>
    <?= $link('settings', 'settings.php', 'gear', 'Settings') ?>
    <?= $link('users', 'users.php', 'person-gear', 'Users') ?>
    <?= $link('audit', 'audit.php', 'clock-history', 'Audit log') ?>
  <?php endif; ?>
  <div class="nav-section">Website</div>
  <a class="nav-link" href="<?= e(url('index.php')) ?>" target="_blank"><i class="bi bi-box-arrow-up-right"></i> View site</a>
  <a class="nav-link" href="<?= e(url('logout.php')) ?>"><i class="bi bi-box-arrow-left"></i> Log out</a>
</aside>
<div class="sidebar-scrim" onclick="document.getElementById('sidebar').classList.remove('show')"></div>
<div class="admin-main">
  <div class="admin-topbar d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2 d-lg-none">
      <button class="btn btn-sm btn-outline-secondary" type="button" aria-label="Open menu" onclick="document.getElementById('sidebar').classList.toggle('show')"><i class="bi bi-list"></i></button>
      <a class="topbar-brand" href="<?= e(url('admin/index.php')) ?>"><?= logo_img('green', 'logo', 40) ?></a>
    </div>
    <form class="admin-search d-none d-md-block" action="<?= e(url('admin/bookings.php')) ?>" method="get" role="search">
      <i class="bi bi-search"></i>
      <input class="form-control form-control-sm" name="q" placeholder="Search booking code, guest or phone" aria-label="Search bookings">
    </form>
    <div class="admin-user">
      <span class="d-none d-sm-inline text-end lh-sm"><?= e($me['name']) ?><br><span class="small text-muted text-capitalize"><?= e($me['role']) ?></span></span>
      <span class="avatar" aria-hidden="true"><?= e($initials) ?></span>
    </div>
  </div>
  <div class="admin-content">
    <?= render_flashes() ?>

<?php
// Public layout header. Set $pageTitle and $activeNav before including.
// Set $headerOverlay = true on pages that open with a full-bleed photo (the home page).
$pageTitle = $pageTitle ?? setting('resort_name', "J'Maire Farm");
$activeNav = $activeNav ?? '';
$headerOverlay = $headerOverlay ?? false;
$user = current_user();
$resort = setting('resort_name', "J'Maire Farm");
$nav = function (string $key, string $href, string $label) use ($activeNav) {
    $active = $activeNav === $key;
    return '<li class="nav-item"><a class="nav-link' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '') .
        ' href="' . e(url($href)) . '">' . e($label) . '</a></li>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#1c2a1c">
  <title><?= e($pageTitle) ?> | <?= e($resort) ?></title>
<?php include __DIR__ . '/head_assets.php'; ?>
</head>
<body>
<a class="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-light btn-sm" style="z-index:2000" href="#main">Skip to content</a>
<header class="site-header<?= $headerOverlay ? ' is-overlay' : '' ?>" id="siteHeader">
  <nav class="navbar navbar-expand-lg" aria-label="Main">
    <div class="container">
      <a class="navbar-brand" href="<?= e(url('index.php')) ?>">
        <?php if ($headerOverlay): ?><?= logo_img('white', 'logo logo-on-dark', 52) ?><?php endif; ?>
        <?= logo_img('green', 'logo logo-on-light', 52) ?>
      </a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Open menu">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="mainNav">
        <ul class="navbar-nav me-auto gap-lg-1">
          <?= $nav('stay', 'accommodations.php', 'Cottages & rooms') ?>
          <?= $nav('menu', 'menu.php', 'Food & activities') ?>
          <?= $nav('find', 'find_booking.php', 'Find my booking') ?>
        </ul>
        <ul class="navbar-nav align-items-lg-center gap-lg-1">
          <?php if ($user): ?>
            <?php if (in_array($user['role'], ['staff', 'admin'], true)): ?>
              <li class="nav-item"><a class="nav-link" href="<?= e(url('admin/index.php')) ?>"><i class="bi bi-grid-1x2 me-1"></i>Dashboard</a></li>
            <?php else: ?>
              <?= $nav('mine', 'my_bookings.php', 'My bookings') ?>
            <?php endif; ?>
            <li class="nav-item dropdown">
              <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-person-circle me-1"></i><?= e(explode(' ', $user['name'])[0]) ?></a>
              <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="<?= e(url('profile.php')) ?>"><i class="bi bi-person me-2"></i>My profile</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="<?= e(url('logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
              </ul>
            </li>
          <?php else: ?>
            <?= $nav('login', 'login.php', 'Log in') ?>
          <?php endif; ?>
          <li class="nav-item ms-lg-2 mt-2 mt-lg-0"><a class="btn btn-sun" href="<?= e(url('book.php')) ?>">Book a visit</a></li>
        </ul>
      </div>
    </div>
  </nav>
</header>
<main id="main">
<?php $flashHtml = render_flashes(); if ($flashHtml): ?>
  <div class="container flash-wrap"<?= $headerOverlay ? ' style="position:relative;z-index:5;padding-top:96px;margin-bottom:-80px"' : '' ?>><?= $flashHtml ?></div>
<?php endif; ?>

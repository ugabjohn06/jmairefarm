<?php
// Layout for login / register / password pages: one "seed packet" card.
// A green field with the logo on top, the form below, and the farm's hours on the back panel.
// Set $packetWide = true for longer forms (register).
$resort = setting('resort_name', "J'Maire Farm");
$noDatepicker = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="theme-color" content="#3D583C">
  <title><?= e($pageTitle ?? 'Login') ?> | <?= e($resort) ?></title>
<?php include __DIR__ . '/head_assets.php'; ?>
</head>
<body class="auth-body">
<main class="auth-wrap">
  <div class="packet<?= !empty($packetWide) ? ' is-wide' : '' ?>">
    <div class="packet-field">
      <a href="<?= e(url('index.php')) ?>" class="brand-link">
        <?= logo_img('white', 'logo', 72) ?>
      </a>
      <p class="packet-tag"><?= e(setting('tagline')) ?>.</p>
    </div>
    <div class="packet-body">
      <?= render_flashes() ?>

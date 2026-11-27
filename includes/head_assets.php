<?php
// Shared <head> assets for every layout (redesign).
// Bootstrap, icons and charts are self-hosted in assets/; the Figtree font comes from
// Google Fonts and Flatpickr (the date picker) from the CDN, so those two need internet.
$cssVersion = '?v=24'; // bump to bust the browser cache after editing app.css (not $v: this file is included into page scope)
?>
  <link rel="icon" href="<?= e(url('assets/img/logo-mark.png')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet">
  <link href="<?= e(url('assets/vendor/bootstrap/bootstrap.min.css')) ?>" rel="stylesheet">
  <link href="<?= e(url('assets/vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>" rel="stylesheet">
<?php if (empty($noDatepicker)): ?>
  <link href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css" rel="stylesheet">
<?php endif; ?>
  <link href="<?= e(url('assets/css/app.css')) . $cssVersion ?>" rel="stylesheet">

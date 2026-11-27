<?php
$resort = setting('resort_name', "J'Maire Farm");
?>
</main>
<footer class="site-footer mt-auto">
  <div class="container">
    <div class="row g-4 g-lg-5">
      <div class="col-lg-4">
        <div class="mb-3"><?= logo_img('white', 'logo', 72) ?></div>
        <p class="mb-0" style="max-width:34ch"><?= e(setting('tagline')) ?></p>
      </div>
      <div class="col-sm-6 col-lg-4">
        <h6>Visit us</h6>
        <ul>
          <li><i class="bi bi-geo-alt"></i><span><?= e(setting('address')) ?></span></li>
          <li><i class="bi bi-sun"></i><span>Day use: <?= e(setting('day_hours')) ?></span></li>
          <li><i class="bi bi-moon-stars"></i><span>Overnight: check-in <?= e(setting('checkin_time')) ?>, check-out <?= e(setting('checkout_time')) ?></span></li>
        </ul>
      </div>
      <div class="col-sm-6 col-lg-4">
        <h6>Contact</h6>
        <ul>
          <li><i class="bi bi-telephone"></i><span><?= e(setting('contact_phone')) ?></span></li>
          <li><i class="bi bi-envelope"></i><span><?= e(setting('contact_email')) ?></span></li>
          <?php if (setting('facebook_url')): ?>
            <li><i class="bi bi-facebook"></i><a href="<?= e(setting('facebook_url')) ?>" target="_blank" rel="noopener">Facebook page</a></li>
          <?php endif; ?>
        </ul>
      </div>
    </div>
    <div class="foot-bottom">
      <span>&copy; <?= date('Y') ?> <?= e($resort) ?></span>
      <span><a href="<?= e(url('find_booking.php')) ?>">Find my booking</a> &middot; <a href="<?= e(url('login.php')) ?>">Staff login</a></span>
    </div>
  </div>
</footer>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<?php if (!empty($headerOverlay)): ?>
<script>
// Header turns solid once the page scrolls past the top of the photo
(function () {
  var h = document.getElementById('siteHeader');
  if (!h) return;
  var onScroll = function () { h.classList.toggle('is-solid', window.scrollY > 40 || document.getElementById('mainNav').classList.contains('show')); };
  window.addEventListener('scroll', onScroll, { passive: true });
  document.getElementById('mainNav').addEventListener('show.bs.collapse', function () { h.classList.add('is-solid'); });
  document.getElementById('mainNav').addEventListener('hidden.bs.collapse', onScroll);
  onScroll();
})();
</script>
<?php endif; ?>
<?= $extraScripts ?? '' ?>
</body>
</html>
<?php clear_old(); ?>

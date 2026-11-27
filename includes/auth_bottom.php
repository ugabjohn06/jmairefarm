      <a class="auth-back" href="<?= e(url('index.php')) ?>"><i class="bi bi-arrow-left"></i> Back to the website</a>
    </div>
    <dl class="packet-spec">
      <div><dt>Day use</dt><dd><?= e(setting('day_hours')) ?></dd></div>
      <div><dt>Overnight</dt><dd>In <?= e(setting('checkin_time')) ?> &middot; Out <?= e(setting('checkout_time')) ?></dd></div>
    </dl>
  </div>
</main>
<script src="<?= e(url('assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>
<?php clear_old(); ?>

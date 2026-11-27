<?php
// Shown when a logged-in user opens a page their role can't access.
audit('access.denied', null, null, ($_SERVER['REQUEST_URI'] ?? ''));
$pageTitle = 'Access denied';
include __DIR__ . '/header.php';
?>
<div class="container py-5 text-center">
  <div class="display-1 text-primary"><i class="bi bi-shield-lock"></i></div>
  <h1 class="mt-3">Access denied</h1>
  <p class="text-muted">Your account doesn't have permission to open this page.</p>
  <a class="btn btn-primary" href="<?= e(url('index.php')) ?>">Back to home</a>
</div>
<?php include __DIR__ . '/footer.php'; ?>

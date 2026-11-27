<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) redirect(is_staff() ? 'admin/index.php' : 'my_bookings.php');

if (is_post()) {
    csrf_check();
    $_SESSION['login_fails'] = array_filter($_SESSION['login_fails'] ?? [], fn($t) => $t > time() - 600);
    if (count($_SESSION['login_fails']) >= 5) {
        flash('error', 'Too many failed attempts. Please wait 10 minutes and try again.');
        redirect('login.php');
    }

    $login = post('login');
    $user = db_one('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1', [$login, $login]);

    if ($user && password_verify((string) ($_POST['password'] ?? ''), $user['password'])) {
        if (!$user['is_active']) {
            flash('error', 'This account has been deactivated. Please contact the farm.');
            redirect('login.php');
        }
        login_user($user);
        unset($_SESSION['login_fails']);
        audit('auth.login', 'user', (int) $user['id']);
        $next = $_SESSION['after_login'] ?? null;
        unset($_SESSION['after_login']);
        if ($next && str_starts_with($next, base_url() . '/') && !str_starts_with($next, '//')) {
            header('Location: ' . $next);
            exit;
        }
        redirect(in_array($user['role'], ['staff', 'admin'], true) ? 'admin/index.php' : 'my_bookings.php');
    }

    $_SESSION['login_fails'][] = time();
    $_SESSION['old'] = ['login' => $login];
    // Same message whether the username or the password was wrong (don't reveal which accounts exist)
    flash('error', 'Incorrect email/username or password.');
    redirect('login.php');
}

$pageTitle = 'Log in';
include __DIR__ . '/includes/auth_top.php';
?>
<h1>Log in</h1>
<p class="auth-sub">See your bookings and receipts, or open the front desk.</p>
<form method="post">
  <?= csrf_field() ?>
  <div class="mb-3">
    <label class="form-label" for="login">Email or username</label>
    <input id="login" name="login" class="form-control form-control-lg" value="<?= e(old('login')) ?>" required autofocus autocomplete="username">
  </div>
  <div class="mb-2">
    <div class="d-flex justify-content-between align-items-baseline">
      <label class="form-label" for="password">Password</label>
      <a class="small" href="<?= e(url('forgot_password.php')) ?>">Forgot password?</a>
    </div>
    <input id="password" name="password" type="password" class="form-control form-control-lg" required autocomplete="current-password">
  </div>
  <button class="btn btn-primary btn-lg w-100 mt-3 mb-3">Log in</button>
  <p class="small mb-0">No account yet? <a href="<?= e(url('register.php')) ?>">Create one</a></p>
</form>
<?php include __DIR__ . '/includes/auth_bottom.php';

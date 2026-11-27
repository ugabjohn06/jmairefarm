<?php
require __DIR__ . '/includes/bootstrap.php';

$token = (string) (post('token') ?: get('token'));
$reset = $token ? db_one('SELECT * FROM password_resets WHERE token_hash = ? AND used = 0 AND expires_at > NOW()', [hash('sha256', $token)]) : null;

if (!$reset) {
    flash('error', 'This reset link is invalid or has expired. Please request a new one.');
    redirect('forgot_password.php');
}

if (is_post()) {
    csrf_check();
    $pass = (string) ($_POST['password'] ?? '');
    if (strlen($pass) < 8 || $pass !== ($_POST['password_confirm'] ?? '')) {
        flash('error', 'Passwords must match and be at least 8 characters.');
        redirect('reset_password.php?token=' . urlencode($token));
    }
    db_exec('UPDATE users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), (int) $reset['user_id']]);
    db_exec('UPDATE password_resets SET used = 1 WHERE user_id = ?', [(int) $reset['user_id']]);
    db_exec('INSERT INTO audit_logs (user_id, action, target_type, target_id, ip_address) VALUES (?, \'auth.password_reset\', \'user\', ?, ?)',
        [(int) $reset['user_id'], (int) $reset['user_id'], client_ip()]);
    flash('success', 'Password updated. You can log in now.');
    redirect('login.php');
}

$pageTitle = 'Set a new password';
include __DIR__ . '/includes/auth_top.php';
?>
<h1>New password</h1>
<p class="auth-sub">Choose a new password for your account.</p>
<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="token" value="<?= e($token) ?>">
  <div class="mb-2"><input name="password" type="password" class="form-control form-control-lg" placeholder="New password (8+ characters)" minlength="8" required></div>
  <div class="mb-3"><input name="password_confirm" type="password" class="form-control form-control-lg" placeholder="Confirm new password" minlength="8" required></div>
  <button class="btn btn-primary btn-lg w-100">Save password</button>
</form>
<?php include __DIR__ . '/includes/auth_bottom.php';

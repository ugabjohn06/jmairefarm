<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_post()) {
    csrf_check();
    $user = db_one('SELECT * FROM users WHERE email = ? AND is_active = 1', [strtolower(post('email'))]);
    if ($user) {
        $token = bin2hex(random_bytes(32));
        db_exec('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))',
            [(int) $user['id'], hash('sha256', $token)]);
        $link = absolute_url('reset_password.php?token=' . $token);
        send_email($user['email'], 'Reset your password',
            '<p>Hi ' . e($user['name']) . ',</p><p>Click the link below to set a new password. It expires in 1 hour.</p><p><a href="' . e($link) . '">' . e($link) . '</a></p><p>If you did not ask for this, you can ignore this email.</p>');
        audit('auth.reset_requested', 'user', (int) $user['id']);
    }
    // Same message either way so nobody can check which emails have accounts
    flash('success', 'If that email has an account, we sent a reset link. (Demo: staff can see it under Admin > Email log.)');
    redirect('forgot_password.php');
}

$pageTitle = 'Forgot password';
include __DIR__ . '/includes/auth_top.php';
?>
<h1>Forgot password</h1>
<p class="auth-sub">Enter your email and we'll send you a link to set a new password.</p>
<form method="post">
  <?= csrf_field() ?>
  <div class="mb-3"><input name="email" type="email" class="form-control form-control-lg" placeholder="Email" required></div>
  <button class="btn btn-primary btn-lg w-100 mb-3">Send reset link</button>
  <p class="text-center small mb-0"><a href="<?= e(url('login.php')) ?>">Back to log in</a></p>
</form>
<?php include __DIR__ . '/includes/auth_bottom.php';

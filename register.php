<?php
require __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) redirect('my_bookings.php');

if (is_post()) {
    csrf_check();
    $name = post('name');
    $email = strtolower(post('email'));
    $phone = post('phone');
    $pass = (string) ($_POST['password'] ?? '');
    $errors = [];

    if (mb_strlen($name) < 2) $errors[] = 'Please enter your full name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    elseif (db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) $errors[] = 'An account with that email already exists. Try logging in.';
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $errors[] = 'Please enter a valid mobile number.';
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pass !== ($_POST['password_confirm'] ?? '')) $errors[] = 'Passwords do not match.';

    if ($errors) {
        remember_input();
        foreach ($errors as $err) flash('error', $err);
        redirect('register.php');
    }

    db_exec('INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, \'guest\')',
        [$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT)]);
    $user = db_one('SELECT * FROM users WHERE id = ?', [db_insert_id()]);
    login_user($user);
    audit('auth.register', 'user', (int) $user['id'], $email);
    flash('success', 'Welcome, ' . $name . '! Your account is ready.');
    $next = $_SESSION['after_login'] ?? null;
    unset($_SESSION['after_login']);
    if ($next && str_starts_with($next, base_url() . '/') && !str_starts_with($next, '//')) {
        header('Location: ' . $next);
        exit;
    }
    redirect('book.php');
}

$pageTitle = 'Create account';
$packetWide = true;
include __DIR__ . '/includes/auth_top.php';
?>
<h1>Create account</h1>
<p class="auth-sub">It takes a minute, and keeps all your bookings in one place.</p>
<form method="post">
  <?= csrf_field() ?>
  <div class="mb-3"><label class="form-label" for="name">Full name</label><input id="name" name="name" class="form-control" value="<?= e(old('name')) ?>" required maxlength="120" autocomplete="name"></div>
  <div class="row g-3 mb-3">
    <div class="col-sm-6"><label class="form-label" for="email">Email</label><input id="email" name="email" type="email" class="form-control" value="<?= e(old('email')) ?>" required maxlength="150" autocomplete="email"></div>
    <div class="col-sm-6"><label class="form-label" for="phone">Mobile number</label><input id="phone" name="phone" type="tel" class="form-control" value="<?= e(old('phone')) ?>" required placeholder="09XX XXX XXXX" autocomplete="tel"></div>
  </div>
  <div class="row g-3 mb-4">
    <div class="col-sm-6"><label class="form-label" for="password">Password</label><input id="password" name="password" type="password" class="form-control" minlength="8" required autocomplete="new-password" aria-describedby="pwHelp"><p class="form-text mt-1 mb-0" id="pwHelp">At least 8 characters.</p></div>
    <div class="col-sm-6"><label class="form-label" for="password_confirm">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" class="form-control" minlength="8" required autocomplete="new-password"></div>
  </div>
  <button class="btn btn-primary btn-lg w-100 mb-3">Create account</button>
  <p class="small mb-0">Already registered? <a href="<?= e(url('login.php')) ?>">Log in</a></p>
</form>
<?php include __DIR__ . '/includes/auth_bottom.php';

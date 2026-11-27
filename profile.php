<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

if (is_post()) {
    csrf_check();
    $name = post('name');
    $phone = post('phone');
    $errors = [];
    if (mb_strlen($name) < 2) $errors[] = 'Please enter your name.';
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) $errors[] = 'Please enter a valid mobile number.';

    $newPass = (string) ($_POST['new_password'] ?? '');
    if ($newPass !== '') {
        $row = db_one('SELECT password FROM users WHERE id = ?', [(int) $user['id']]);
        if (!password_verify((string) ($_POST['current_password'] ?? ''), $row['password'])) $errors[] = 'Your current password is incorrect.';
        if (strlen($newPass) < 8) $errors[] = 'New password must be at least 8 characters.';
    }
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        redirect('profile.php');
    }
    db_exec('UPDATE users SET name = ?, phone = ? WHERE id = ?', [$name, $phone ?: null, (int) $user['id']]);
    if ($newPass !== '') {
        db_exec('UPDATE users SET password = ? WHERE id = ?', [password_hash($newPass, PASSWORD_DEFAULT), (int) $user['id']]);
        audit('user.password_change', 'user', (int) $user['id']);
    }
    flash('success', 'Profile saved.');
    redirect('profile.php');
}

$pageTitle = 'My profile';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
  <div class="narrow-card" style="max-width:640px">
  <h1 class="mb-1">My profile</h1>
  <p class="text-muted mb-4">Your contact details are copied into new bookings.</p>
  <form method="post" class="card p-4 p-md-5">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label">Email</label><input class="form-control" value="<?= e($user['email']) ?>" disabled></div>
    <div class="mb-3"><label class="form-label">Full name</label><input name="name" class="form-control" value="<?= e($user['name']) ?>" required></div>
    <div class="mb-3"><label class="form-label">Mobile number</label><input name="phone" class="form-control" value="<?= e($user['phone']) ?>"></div>
    <hr>
    <h6>Change password <small class="text-muted fw-normal">(leave blank to keep)</small></h6>
    <div class="row g-2 mb-3">
      <div class="col-md-6"><input name="current_password" type="password" class="form-control" placeholder="Current password" autocomplete="current-password"></div>
      <div class="col-md-6"><input name="new_password" type="password" class="form-control" placeholder="New password" minlength="8" autocomplete="new-password"></div>
    </div>
    <button class="btn btn-primary">Save changes</button>
  </form>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php';

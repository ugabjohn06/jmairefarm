<?php
require __DIR__ . '/../includes/bootstrap.php';
$me = require_admin();

if (is_post()) {
    csrf_check();
    $action = post('action');
    $id = (int) post('id');
    $target = $id ? db_one('SELECT * FROM users WHERE id = ?', [$id]) : null;

    if ($action === 'create') {
        $name = post('name');
        $email = strtolower(post('email'));
        $username = post('username') ?: null;
        $role = in_array(post('role'), ['guest', 'staff', 'admin'], true) ? post('role') : 'staff';
        $pass = (string) ($_POST['password'] ?? '');
        $err = null;
        if (mb_strlen($name) < 2) $err = 'Enter a name.';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Enter a valid email.';
        elseif (db_value('SELECT COUNT(*) FROM users WHERE email = ?', [$email])) $err = 'That email is already used.';
        elseif ($username && !preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) $err = 'Username: 3-50 letters, numbers, dots or underscores.';
        elseif ($username && db_value('SELECT COUNT(*) FROM users WHERE username = ?', [$username])) $err = 'That username is taken.';
        elseif (strlen($pass) < 8) $err = 'Password must be at least 8 characters.';
        if ($err) {
            remember_input();
            flash('error', $err);
            redirect('admin/users.php');
        }
        db_exec('INSERT INTO users (name, email, username, phone, password, role) VALUES (?, ?, ?, ?, ?, ?)',
            [$name, $email, $username, post('phone') ?: null, password_hash($pass, PASSWORD_DEFAULT), $role]);
        audit('user.create', 'user', db_insert_id(), "$name ($role)");
        flash('success', "Account for $name created.");
    } elseif ($target && $id === (int) $me['id'] && in_array($action, ['role', 'toggle'], true)) {
        flash('error', 'You cannot change your own role or deactivate yourself.');
    } elseif ($target && $action === 'role') {
        $role = in_array(post('role'), ['guest', 'staff', 'admin'], true) ? post('role') : $target['role'];
        db_exec('UPDATE users SET role = ? WHERE id = ?', [$role, $id]);
        audit('user.role', 'user', $id, "{$target['name']}: {$target['role']} -> $role");
        flash('success', "{$target['name']} is now $role.");
    } elseif ($target && $action === 'toggle') {
        db_exec('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
        audit($target['is_active'] ? 'user.deactivate' : 'user.activate', 'user', $id, $target['name']);
        flash('success', $target['name'] . ($target['is_active'] ? ' deactivated.' : ' activated.'));
    } elseif ($target && $action === 'password') {
        $pass = (string) ($_POST['password'] ?? '');
        if (strlen($pass) < 8) {
            flash('error', 'Password must be at least 8 characters.');
        } else {
            db_exec('UPDATE users SET password = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            audit('user.password_reset', 'user', $id, $target['name']);
            flash('success', 'Password for ' . $target['name'] . ' was changed.');
        }
    }
    redirect('admin/users.php' . (get('role') ? '?role=' . urlencode(get('role')) : ''));
}

$roleFilter = in_array(get('role'), ['guest', 'staff', 'admin'], true) ? get('role') : '';
$users = $roleFilter
    ? db_all('SELECT * FROM users WHERE role = ? ORDER BY name', [$roleFilter])
    : db_all("SELECT * FROM users ORDER BY FIELD(role,'admin','staff','guest'), name");

$pageTitle = 'Users';
$activeAdmin = 'users';
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1>Users</h1>
  <div class="btn-group btn-group-sm">
    <a class="btn btn-outline-primary <?= !$roleFilter ? 'active' : '' ?>" href="users.php">All</a>
    <a class="btn btn-outline-primary <?= $roleFilter === 'admin' ? 'active' : '' ?>" href="?role=admin">Admins</a>
    <a class="btn btn-outline-primary <?= $roleFilter === 'staff' ? 'active' : '' ?>" href="?role=staff">Staff</a>
    <a class="btn btn-outline-primary <?= $roleFilter === 'guest' ? 'active' : '' ?>" href="?role=guest">Guests</a>
  </div>
</div>
<div class="row g-4">
  <div class="col-xl-8">
    <div class="card"><div class="table-responsive">
      <table class="table table-jm mb-0">
        <thead><tr><th>Name</th><th>Login</th><th>Role</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($users as $u): $self = (int) $u['id'] === (int) $me['id']; ?>
            <tr class="<?= $u['is_active'] ? '' : 'text-muted' ?>">
              <td><?= e($u['name']) ?><?= $self ? ' <span class="badge text-bg-light">you</span>' : '' ?><br><span class="small text-muted"><?= e($u['phone']) ?></span></td>
              <td class="small"><?= e($u['email']) ?><?= $u['username'] ? '<br>@' . e($u['username']) : '' ?></td>
              <td>
                <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <select name="role" class="form-select form-select-sm" style="width:auto" <?= $self ? 'disabled' : '' ?> onchange="this.form.submit()">
                    <?php foreach (['guest', 'staff', 'admin'] as $r): ?><option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?>
                  </select></form>
              </td>
              <td><?= $u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Deactivated</span>' ?></td>
              <td class="text-end text-nowrap">
                <?php if (!$self): ?>
                  <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="btn btn-sm btn-outline-secondary"><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
                <?php endif; ?>
                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#pw<?= (int) $u['id'] ?>"><i class="bi bi-key"></i></button>
                <form method="post" class="collapse mt-1" id="pw<?= (int) $u['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="password"><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <div class="input-group input-group-sm"><input type="password" name="password" class="form-control" placeholder="New password" minlength="8" required><button class="btn btn-primary">Set</button></div></form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
  <div class="col-xl-4">
    <form method="post" class="card p-3">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <h5>Create account</h5>
      <div class="mb-2"><label class="form-label small">Name</label><input name="name" class="form-control" value="<?= e(old('name')) ?>" required></div>
      <div class="mb-2"><label class="form-label small">Email</label><input type="email" name="email" class="form-control" value="<?= e(old('email')) ?>" required></div>
      <div class="row g-2 mb-2">
        <div class="col"><label class="form-label small">Username <small class="text-muted">(optional)</small></label><input name="username" class="form-control" value="<?= e(old('username')) ?>"></div>
        <div class="col"><label class="form-label small">Phone</label><input name="phone" class="form-control" value="<?= e(old('phone')) ?>"></div>
      </div>
      <div class="row g-2 mb-3">
        <div class="col"><label class="form-label small">Role</label><select name="role" class="form-select"><option value="staff">Staff</option><option value="admin">Admin</option><option value="guest">Guest</option></select></div>
        <div class="col"><label class="form-label small">Password</label><input type="password" name="password" class="form-control" minlength="8" required></div>
      </div>
      <button class="btn btn-primary">Create</button>
      <p class="small text-muted mt-2 mb-0"><strong>Staff</strong> can manage bookings, payments and walk-ins. <strong>Admins</strong> can also change rates, settings, users and see income reports.</p>
    </form>
  </div>
</div>
<?php include __DIR__ . '/../includes/admin_footer.php';

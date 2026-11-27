<?php
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$userId = (int) get('user_id');
$action = get('action');
$where = ['1=1'];
$params = [];
if ($userId) { $where[] = 'l.user_id = ?'; $params[] = $userId; }
if ($action !== '') { $where[] = 'l.action LIKE ?'; $params[] = $action . '%'; }
$whereSql = implode(' AND ', $where);

[$limit, $offset, $page] = paginate(50);
$total = (int) db_value("SELECT COUNT(*) FROM audit_logs l WHERE $whereSql", $params);
$logs = db_all("SELECT l.*, u.name AS user_name, u.role, b.booking_code
                FROM audit_logs l LEFT JOIN users u ON u.id = l.user_id
                LEFT JOIN bookings b ON l.target_type = 'booking' AND b.id = l.target_id
                WHERE $whereSql ORDER BY l.id DESC LIMIT $limit OFFSET $offset", $params);
$staff = db_all("SELECT id, name FROM users WHERE role IN ('staff','admin') ORDER BY name");

$pageTitle = 'Audit log';
$activeAdmin = 'audit';
include __DIR__ . '/../includes/admin_header.php';
?>
<h1 class="mb-1">Audit log</h1>
<p class="text-muted small">Every approval, cancellation, payment and setting change, with who did it and when.</p>
<form class="card p-3 mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small mb-1">User</label><select name="user_id" class="form-select form-select-sm"><option value="">Everyone</option>
      <?php foreach ($staff as $s): ?><option value="<?= (int) $s['id'] ?>" <?= $userId === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-4"><label class="form-label small mb-1">Action</label><select name="action" class="form-select form-select-sm"><option value="">All</option>
      <?php foreach (['booking' => 'Bookings', 'payment' => 'Payments', 'auth' => 'Logins', 'accommodation' => 'Accommodations', 'settings' => 'Settings', 'user' => 'Users', 'menu' => 'Menu', 'access' => 'Access denied'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= $action === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 d-grid"><button class="btn btn-sm btn-primary">Filter</button></div>
  </div>
</form>
<div class="card"><div class="table-responsive">
  <table class="table table-sm table-jm mb-0">
    <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td class="small text-nowrap"><?= e(fmt_datetime($l['created_at'])) ?></td>
          <td class="small"><?= e($l['user_name'] ?? 'System / guest') ?><?= $l['role'] ? ' <span class="text-muted">(' . e($l['role']) . ')</span>' : '' ?></td>
          <td><code><?= e($l['action']) ?></code></td>
          <td class="small"><?php if ($l['booking_code']): ?><a href="booking.php?id=<?= (int) $l['target_id'] ?>"><?= e($l['booking_code']) ?></a> <?php endif; ?><?= e($l['details']) ?></td>
          <td class="small text-muted"><?= e($l['ip_address']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div></div>
<div class="mt-3"><?= pagination_links($total, $limit, $page) ?></div>
<?php include __DIR__ . '/../includes/admin_footer.php';

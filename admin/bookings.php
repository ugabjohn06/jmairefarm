<?php
require __DIR__ . '/../includes/bootstrap.php';
require_staff();

$view = get('view') === 'payments' ? 'payments' : 'bookings';
$status = array_key_exists(get('status'), BOOKING_STATUSES) ? get('status') : '';
$accId = (int) get('accommodation_id');
$from = valid_date(get('from')) ? get('from') : '';
$to = valid_date(get('to')) ? get('to') : '';
$q = get('q');
$source = in_array(get('source'), ['online', 'walk_in'], true) ? get('source') : '';

$where = ['1=1'];
$params = [];
if ($status) { $where[] = 'b.status = ?'; $params[] = $status; }
if ($accId) { $where[] = 'b.accommodation_id = ?'; $params[] = $accId; }
if ($from) { $where[] = 'b.check_out > ?'; $params[] = $from; }
if ($to) { $where[] = 'b.check_in <= ?'; $params[] = $to; }
if ($source) { $where[] = 'b.source = ?'; $params[] = $source; }
if ($q !== '') {
    $where[] = '(b.booking_code LIKE ? OR b.guest_name LIKE ? OR b.guest_phone LIKE ? OR b.guest_email LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($view === 'payments') {
    $where[] = "EXISTS (SELECT 1 FROM payments p WHERE p.booking_id = b.id AND p.status = 'pending') AND b.status IN ('pending','confirmed','checked_in')";
}
$whereSql = implode(' AND ', $where);

[$limit, $offset, $page] = paginate(25);
$total = (int) db_value("SELECT COUNT(*) FROM bookings b WHERE $whereSql", $params);
$rows = db_all("SELECT b.*, a.name AS accommodation_name, a.type,
                  (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.booking_id = b.id AND p.status='verified') AS amount_paid,
                  (SELECT COUNT(*) FROM payments p WHERE p.booking_id = b.id AND p.status='pending') AS pending_payments
                FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
                WHERE $whereSql
                ORDER BY " . ($view === 'payments' ? 'b.check_in ASC' : "FIELD(b.status,'pending','checked_in','confirmed') DESC, b.check_in DESC, b.id DESC") . "
                LIMIT $limit OFFSET $offset", $params);

// One unique match on a search? Jump straight to it.
if ($q !== '' && $total === 1 && $page === 1) redirect('admin/booking.php?id=' . (int) $rows[0]['id']);

$units = db_all('SELECT id, name FROM accommodations ORDER BY sort_order, name');
$pageTitle = $view === 'payments' ? 'Payments to verify' : 'Bookings';
$activeAdmin = $view === 'payments' ? 'payments' : 'bookings';
include __DIR__ . '/../includes/admin_header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1><?= e($pageTitle) ?> <small class="text-muted fs-6"><?= $total ?> found</small></h1>
  <div class="d-flex gap-2">
    <?php if (is_admin()): ?><a class="btn btn-outline-secondary" href="export.php?type=bookings&<?= e(http_build_query($_GET)) ?>"><i class="bi bi-download"></i> Export CSV</a><?php endif; ?>
    <a class="btn btn-primary" href="walkin.php"><i class="bi bi-person-plus"></i> Walk-in</a>
  </div>
</div>

<?php if ($view === 'bookings'): ?>
<form class="card p-3 mb-3" method="get">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label small mb-1">Search</label><input name="q" class="form-control form-control-sm" value="<?= e($q) ?>" placeholder="Code, name, phone, email"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Status</label>
      <select name="status" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach (BOOKING_STATUSES as $k => [$label]): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Unit</label>
      <select name="accommodation_id" class="form-select form-select-sm"><option value="">All</option>
        <?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>" <?= $accId === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Stay from</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">to</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($to) ?>"></div>
    <div class="col-md-1 d-grid"><button class="btn btn-sm btn-primary">Filter</button></div>
  </div>
  <div class="mt-2 small">
    Quick: <a href="?status=pending">Pending</a> &middot; <a href="?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>">Today</a> &middot;
    <a href="?status=confirmed&from=<?= date('Y-m-d') ?>">Upcoming confirmed</a> &middot; <a href="?source=walk_in">Walk-ins</a> &middot; <a href="bookings.php">Clear</a>
  </div>
</form>
<?php endif; ?>

<div class="card">
  <div class="table-responsive">
    <table class="table table-hover table-jm mb-0">
      <thead><tr><th>Code</th><th>Guest</th><th>Unit</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Paid</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $b): ?>
          <tr>
            <td><a href="booking.php?id=<?= (int) $b['id'] ?>" class="fw-semibold"><?= e($b['booking_code']) ?></a>
              <?= $b['source'] === 'walk_in' ? '<br><span class="badge text-bg-light">walk-in</span>' : '' ?></td>
            <td><?= e($b['guest_name']) ?><br><span class="small text-muted"><?= e($b['guest_phone']) ?></span></td>
            <td><?= e($b['accommodation_name']) ?></td>
            <td class="small"><?= e(stay_label($b)) ?></td>
            <td class="text-end"><?= money($b['total_amount']) ?></td>
            <td class="text-end"><?= money($b['amount_paid']) ?></td>
            <td><?= status_badge($b['status']) ?>
              <?= $b['pending_payments'] ? '<br><span class="badge text-bg-warning mt-1"><i class="bi bi-receipt"></i> receipt to check</span>' : '' ?></td>
            <td><a class="btn btn-sm btn-outline-primary" href="booking.php?id=<?= (int) $b['id'] ?>">Open</a></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4"><?= $view === 'payments' ? 'No receipts waiting. All caught up!' : 'No bookings match your filters.' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="mt-3"><?= pagination_links($total, $limit, $page) ?></div>
<?php include __DIR__ . '/../includes/admin_footer.php';

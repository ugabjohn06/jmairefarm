<?php
// CSV export (opens directly in Excel). ?type=bookings|payments&from=&to=
require __DIR__ . '/../includes/bootstrap.php';
require_admin();

$type = get('type') === 'payments' ? 'payments' : 'bookings';
$from = valid_date(get('from')) ? get('from') : '2000-01-01';
$to = valid_date(get('to')) ? get('to') : '2100-01-01';
$toExcl = date('Y-m-d', strtotime("$to +1 day"));

if ($type === 'payments') {
    $rows = db_all("SELECT p.paid_at, b.booking_code, b.guest_name, p.amount, p.method, p.payment_type, p.reference_no, p.status, v.name AS verified_by, p.verified_at
                    FROM payments p JOIN bookings b ON b.id = p.booking_id LEFT JOIN users v ON v.id = p.verified_by
                    WHERE p.paid_at >= ? AND p.paid_at < ? ORDER BY p.paid_at", [$from, $toExcl]);
    $headers = ['Paid at', 'Booking code', 'Guest', 'Amount', 'Method', 'Type', 'Reference', 'Status', 'Verified by', 'Verified at'];
} else {
    $where = ['b.check_in >= ?', 'b.check_in < ?'];
    $params = [$from, $toExcl];
    if (array_key_exists(get('status'), BOOKING_STATUSES)) { $where[] = 'b.status = ?'; $params[] = get('status'); }
    $rows = db_all('SELECT b.booking_code, b.status, b.source, b.guest_name, b.guest_phone, b.guest_email, a.name AS unit, b.check_in, b.check_out,
                           b.num_adults, b.num_kids, b.total_amount, b.downpayment_due,
                           (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.booking_id = b.id AND p.status = \'verified\') AS paid, b.created_at
                    FROM bookings b JOIN accommodations a ON a.id = b.accommodation_id
                    WHERE ' . implode(' AND ', $where) . ' ORDER BY b.check_in', $params);
    $headers = ['Code', 'Status', 'Source', 'Guest', 'Phone', 'Email', 'Unit', 'Check-in', 'Check-out', 'Adults', 'Kids', 'Total', 'Downpayment', 'Paid', 'Created'];
}

audit('report.export', null, null, "$type $from to $to (" . count($rows) . ' rows)');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="jmaire-' . $type . '-' . $from . '-to-' . $to . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the ₱ and ñ correctly
fputcsv($out, $headers);
foreach ($rows as $r) {
    // Prevent CSV formula injection in Excel
    fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) && !is_numeric($v) ? "'" . $v : $v, array_values($r)));
}
fclose($out);
